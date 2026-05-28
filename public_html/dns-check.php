<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$SECRET = 'xK9-mP2-qL7nR4';
if (($_GET['token'] ?? '') !== $SECRET) {
    http_response_code(403);
    die(json_encode(['error' => 'forbidden']));
}

// ===== DB CONFIG =====
$DB_HOST = '10.db.sigmanet.lv:3306';
$DB_NAME = 'c_stonekat';
$DB_USER = 'stonekat';
$DB_PASS = '2Q52K9LkYdcm';

// CONNECT FIRST — before any action block
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['error' => 'db_connect_failed']));
}

// register action — now $pdo exists
if (($_GET['action'] ?? '') === 'register') {
    $domain = trim($_GET['domain'] ?? '');
    if ($domain) {
        $pdo->prepare(
            "INSERT IGNORE INTO domain_checks (domain) VALUES (?)"
        )->execute([$domain]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$domains = array_filter(
    array_map('trim', explode(',', $_GET['domains'] ?? ''))
);
if (empty($domains)) { echo json_encode([]); exit; }

// Fetch all requested domains in one query
$placeholders = implode(',', array_fill(0, count($domains), '?'));
$stmt = $pdo->prepare(
    "SELECT * FROM domain_checks WHERE domain IN ({$placeholders})"
);
$stmt->execute(array_values($domains));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Index by domain
$dbData = [];
foreach ($rows as $row) {
    $dbData[$row['domain']] = $row;
}

// Build response — domains not yet in DB get empty placeholder
$results = [];
foreach ($domains as $domain) {
    if (isset($dbData[$domain])) {
        $r = $dbData[$domain];
        $results[$domain] = [
            'status'     => $r['ip_status']  ?? 'no_record',
            'label'      => $r['ip_label']   ?? 'Not checked yet',
            'ns_status'  => $r['ns_status']  ?? 'no_ns',
            'ns_label'   => $r['ns_label']   ?? '',
            'ssl_issued' => $r['ssl_issued'] ?? '',
            'ssl_issuer' => $r['ssl_issuer'] ?? '',
            'checked_at' => $r['checked_at'] ?? '',
        ];
    } else {
        // Not in DB yet — tell the sheet to show pending
        $results[$domain] = [
            'status'     => 'no_record',
            'label'      => 'Pending check',
            'ns_status'  => 'no_ns',
            'ns_label'   => '',
            'ssl_issued' => '',
            'ssl_issuer' => '',
            'checked_at' => '',
        ];
    }
}

echo json_encode($results);
