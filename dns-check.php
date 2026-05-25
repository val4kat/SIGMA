<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$SECRET = 'xK9-mP2-qL7nR4';

if (($_GET['token'] ?? '') !== $SECRET) {
    http_response_code(403);
    die(json_encode(['error' => 'forbidden']));
}

$domains = array_filter(
    array_map('trim', explode(',', $_GET['domains'] ?? ''))
);

if (empty($domains)) {
    echo json_encode([]);
    exit;
}

$OUR_SUBNETS = [
    '92.240.64.', '92.240.65.', '92.240.66.',
    '92.240.72.', '92.240.74.', '92.240.80.'
];

$OUR_NS = ['a.sigmanet.lv', 'b.sigmanet.lv'];

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

function getCountry($ip) {
    $cacheDir  = sys_get_temp_dir() . '/dns_cache/';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . md5($ip) . '.txt';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        return file_get_contents($cacheFile);
    }
    $raw = fetchURL("http://ip-api.com/json/{$ip}?fields=countryCode");
    $cc  = '?';
    if ($raw) {
        $d  = json_decode($raw, true);
        $cc = $d['countryCode'] ?? '?';
    }
    file_put_contents($cacheFile, $cc);
    return $cc;
}

/***** SSL CERT CACHE *****/
function getSSLCertInfo($domain) {
    static $cache = [];

    // Already fetched for this request?
    if (isset($cache[$domain])) {
        return $cache[$domain];
    }

    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
        ]
    ]);

    $socket = @stream_socket_client(
        "ssl://{$domain}:443",
        $errno,
        $errstr,
        8,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return $cache[$domain] = null;
    }

    $params = stream_context_get_params($socket);
    fclose($socket);

    $cert = $params['options']['ssl']['peer_certificate'] ?? null;

    if (!$cert) {
        return $cache[$domain] = null;
    }

    $info = openssl_x509_parse($cert);

    return $cache[$domain] = $info ?: null;
}

/***** SSL ISSUED DATE *****/
function getSSLIssuedDate($domain) {
    $info = getSSLCertInfo($domain);

    if (!$info) return 'No SSL';

    $ts = $info['validFrom_time_t'] ?? null;

    return $ts
        ? gmdate('M j H:i:s Y', $ts) . ' GMT'
        : 'No SSL';
}

/***** SSL ISSUER *****/
function getSSLIssuer($domain) {
    $info = getSSLCertInfo($domain);

    if (!$info) return 'No SSL';

    return $info['issuer']['O']
        ?? $info['issuer']['CN']
        ?? 'Unknown';
}

$results = [];
foreach ($domains as $domain) {

    // ===== A RECORD =====
    $raw = fetchURL("https://dns.google/resolve?name=" . urlencode($domain) . "&type=A");
    $ips = [];
    if ($raw) {
        $data = json_decode($raw, true);
        foreach (($data['Answer'] ?? []) as $r) {
            if ($r['type'] === 1) $ips[] = $r['data'];
        }
        $ips = array_unique($ips);
    }

    // ===== NS RECORD =====
    $rawNS = fetchURL("https://dns.google/resolve?name=" . urlencode($domain) . "&type=NS");
    $nsServers = [];
    if ($rawNS) {
        $dataNS = json_decode($rawNS, true);
        foreach (($dataNS['Answer'] ?? []) as $r) {
            if ($r['type'] === 2) {
                $nsServers[] = strtolower(rtrim($r['data'], '.'));
            }
        }
        $nsServers = array_unique($nsServers);
    }

    if (empty($nsServers)) {
        $ns_status = 'no_ns';
        $ns_label  = 'No NS record';
    } else {
        $hasOurNS  = !empty(array_intersect($nsServers, $GLOBALS['OUR_NS']));
        $ns_status = $hasOurNS ? 'ours' : 'foreign';
        $ns_label  = implode(' | ', $nsServers);
    }

    // ===== SSL ISSUED DATE =====
    $ssl_issued = getSSLIssuedDate($domain);
    // ===== SSL ISSUER =====
    $ssl_issuer = getSSLIssuer($domain);
    
    // ===== BUILD RESULT =====
    
    if (empty($ips)) {
        $results[$domain] = [
            'status'     => 'no_record',
            'label'      => 'No A record',
            'ns_status'  => $ns_status,
            'ns_label'   => $ns_label,
            'ssl_issued' => $ssl_issued,
            'ssl_issuer' => $ssl_issuer,
        ];
        continue;
    }

    // BEFORE
    // $ourIPs = array_filter($ips, fn($ip) => isOurIP($ip, $OUR_SUBNETS));
    // AFTER (works on all PHP versions):
    $ourIPs = array_filter($ips, function($ip) use ($OUR_SUBNETS) {
    return isOurIP($ip, $OUR_SUBNETS);
    });

    if (!empty($ourIPs)) {
        $ip_status = 'ours';
        $ip_label  = implode(', ', $ourIPs);
    } else {
        $parts = [];
        $hasLV = false;
        foreach (array_unique($ips) as $ip) {
            $cc = getCountry($ip);
            if ($cc === 'LV') $hasLV = true;
            $parts[] = "{$ip} ({$cc})";
        }
        $ip_status = $hasLV ? 'lv' : 'foreign';
        $ip_label  = implode(', ', $parts);
    }

    $results[$domain] = [
        'status'     => $ip_status,
        'label'      => $ip_label,
        'ns_status'  => $ns_status,
        'ns_label'   => $ns_label,
        'ssl_issued' => $ssl_issued,
        'ssl_issuer' => $ssl_issuer,     
    ];
}

echo json_encode($results);
