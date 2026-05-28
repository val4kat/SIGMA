<?php
// Can be called via browser too:
// https://stonekat.id.lv/dns-worker.php?token=xK9-mP2-qL7nR4
// Or via cron: php /path/to/dns-worker.php

$SECRET = 'xK9-mP2-qL7nR4';

// CLI or web
$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    if (($_GET['token'] ?? '') !== $SECRET) {
        http_response_code(403);
        die(json_encode(['error' => 'forbidden']));
    }
}

$DB_HOST = '10.db.sigmanet.lv:3306';
$DB_NAME = 'c_stonekat';
$DB_USER = 'stonekat';
$DB_PASS = '2Q52K9LkYdcm';

$REFRESH_HOURS = 20; // re-check domains older than this

$OUR_SUBNETS = [
    '92.240.64.', '92.240.65.', '92.240.66.',
    '92.240.72.', '92.240.74.', '92.240.80.'
];
$OUR_NS = ['a.sigmanet.lv', 'b.sigmanet.lv'];

// ===== CONNECT =====
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("DB connect failed: " . $e->getMessage());
}

// ===== GET STALE DOMAINS =====
// Worker refreshes domains that haven't been checked recently
$stmt = $pdo->prepare(
    "SELECT domain FROM domain_checks 
     WHERE checked_at IS NULL 
        OR checked_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
     ORDER BY checked_at ASC"
);
$stmt->execute([$REFRESH_HOURS]);
$stale = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($stale)) {
    echo $isCLI ? "All domains up to date.\n" : json_encode(['message' => 'all up to date']);
    exit;
}

echo $isCLI ? "Checking " . count($stale) . " domains...\n" : '';

// ===== HELPER FUNCTIONS =====
function isOurIP($ip, $subnets) {
    foreach ($subnets as $s) {
        if (strpos($ip, $s) === 0) return true;
    }
    return false;
}

function fetchURL($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r ?: false;
    }
    return @file_get_contents($url);
}

function getCountry($ip, $pdo) {
    // Cache country in DB could be added, for now use file cache
    $cacheDir  = sys_get_temp_dir() . '/dns_cache/';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . md5($ip) . '.txt';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        return file_get_contents($cacheFile);
    }
    $raw = fetchURL("http://ip-api.com/json/{$ip}?fields=countryCode");
    $cc  = '?';
    if ($raw) { $d = json_decode($raw, true); $cc = $d['countryCode'] ?? '?'; }
    file_put_contents($cacheFile, $cc);
    return $cc;
}

function getSSLInfo($domain) {
    $context = stream_context_create([
        'ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $socket = @stream_socket_client("ssl://{$domain}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) return ['issued' => 'No SSL', 'issuer' => 'No SSL'];
    $params = stream_context_get_params($socket);
    fclose($socket);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert) return ['issued' => 'No SSL', 'issuer' => 'No SSL'];
    $info = openssl_x509_parse($cert);
    if (!$info) return ['issued' => 'No SSL', 'issuer' => 'No SSL'];
    $ts     = $info['validFrom_time_t'] ?? null;
    $issued = $ts ? gmdate('M j H:i:s Y', $ts) . ' GMT' : 'No SSL';
    $issArr = $info['issuer'] ?? [];
    $issuer = $issArr['O'] ?? ($issArr['CN'] ?? 'Unknown');
    return ['issued' => $issued, 'issuer' => $issuer];
}

// ===== UPSERT PREPARED STATEMENT =====
$upsert = $pdo->prepare("
    INSERT INTO domain_checks 
        (domain, ip_label, ip_status, ns_label, ns_status, ssl_issued, ssl_issuer, checked_at)
    VALUES 
        (:domain, :ip_label, :ip_status, :ns_label, :ns_status, :ssl_issued, :ssl_issuer, NOW())
    ON DUPLICATE KEY UPDATE
        ip_label   = VALUES(ip_label),
        ip_status  = VALUES(ip_status),
        ns_label   = VALUES(ns_label),
        ns_status  = VALUES(ns_status),
        ssl_issued = VALUES(ssl_issued),
        ssl_issuer = VALUES(ssl_issuer),
        checked_at = NOW()
");

// ===== PROCESS EACH STALE DOMAIN =====
$done = 0;
foreach ($stale as $domain) {

    // A record
    $raw = fetchURL("https://dns.google/resolve?name=" . urlencode($domain) . "&type=A");
    $ips = [];
    if ($raw) {
        $data = json_decode($raw, true);
        foreach (($data['Answer'] ?? []) as $r) {
            if ($r['type'] === 1) $ips[] = $r['data'];
        }
        $ips = array_unique($ips);
    }

    // NS record
    $rawNS = fetchURL("https://dns.google/resolve?name=" . urlencode($domain) . "&type=NS");
    $nsServers = [];
    if ($rawNS) {
        $dataNS = json_decode($rawNS, true);
        foreach (($dataNS['Answer'] ?? []) as $r) {
            if ($r['type'] === 2) $nsServers[] = strtolower(rtrim($r['data'], '.'));
        }
        $nsServers = array_unique($nsServers);
    }

    if (empty($nsServers)) {
        $ns_status = 'no_ns'; $ns_label = 'No NS record';
    } else {
        $hasOurNS  = !empty(array_intersect($nsServers, $GLOBALS['OUR_NS']));
        $ns_status = $hasOurNS ? 'ours' : 'foreign';
        $ns_label  = implode(' | ', $nsServers);
    }

    // SSL
    $ssl = getSSLInfo($domain);

    // IP status
    if (empty($ips)) {
        $ip_status = 'no_record'; $ip_label = 'No A record';
    } else {
        $ourIPs = array_filter($ips, function($ip) use ($OUR_SUBNETS) {
            return isOurIP($ip, $OUR_SUBNETS);
        });
        if (!empty($ourIPs)) {
            $ip_status = 'ours';
            $ip_label  = implode(', ', $ourIPs);
        } else {
            $parts = []; $hasLV = false;
            foreach (array_unique($ips) as $ip) {
                $cc = getCountry($ip, $pdo);
                if ($cc === 'LV') $hasLV = true;
                $parts[] = "{$ip} ({$cc})";
            }
            $ip_status = $hasLV ? 'lv' : 'foreign';
            $ip_label  = implode(', ', $parts);
        }
    }

    $upsert->execute([
        ':domain'     => $domain,
        ':ip_label'   => $ip_label,
        ':ip_status'  => $ip_status,
        ':ns_label'   => $ns_label,
        ':ns_status'  => $ns_status,
        ':ssl_issued' => $ssl['issued'],
        ':ssl_issuer' => $ssl['issuer'],
    ]);

    $done++;
    if ($isCLI) echo "  [{$done}] {$domain} → {$ip_status}\n";
}

$msg = "Done: {$done} domains updated.";
echo $isCLI ? $msg . "\n" : json_encode(['message' => $msg, 'updated' => $done]);
