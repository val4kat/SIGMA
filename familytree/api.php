<?php
// ═══════════════════════════════════════════════════════════════
// Family Tree Research — PHP API  (api.php)
// Place this file in the same directory as family-tree.html
// ═══════════════════════════════════════════════════════════════

// ── DATABASE CONFIG ─────────────────────────────────────────────
define('DB_HOST', '10.db.sigmanet.lv');
define('DB_NAME', 'c_stonekat');   // ← change this
define('DB_USER', 'stonekat');     // ← change this
define('DB_PASS', '2Q52K9LkYdcm');     // ← change this
define('DB_PORT', 3306);

// ── OPTIONAL API KEY ─────────────────────────────────────────────
// Set to a long random string to require callers to send
//   X-Api-Key: <your_key>   in every request.
// Leave empty string '' to disable authentication (local/trusted use only).
define('API_KEY', '');

// ── CORS & HEADERS ───────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── AUTH ─────────────────────────────────────────────────────────
if (API_KEY !== '') {
    $supplied = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals(API_KEY, $supplied)) {
        respond(['error' => 'Unauthorized'], 401);
    }
}

// ── DB CONNECTION ─────────────────────────────────────────────────
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    respond(['error' => 'Database connection failed: ' . $e->getMessage()], 500);
}

// ── ROUTING ──────────────────────────────────────────────────────
$method   = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['r']  ?? '';
$id       = $_GET['id'] ?? '';
$body     = [];

if (in_array($method, ['POST','PUT','PATCH'])) {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
}

switch ($resource) {
    case 'all':           handleAll();           break;
    case 'persons':       handlePersons();        break;
    case 'couples':       handleCouples();        break;
    case 'parent_child':  handleParentChild();    break;
    default:
        respond(['error' => "Unknown resource: $resource"], 404);
}

// ════════════════════════════════════════════════════════════════
// HANDLERS
// ════════════════════════════════════════════════════════════════

// ── GET ALL  (used for polling) ───────────────────────────────────
function handleAll() {
    global $pdo;
    $persons     = $pdo->query('SELECT * FROM persons     ORDER BY last_name, first_name')->fetchAll();
    $couples     = $pdo->query('SELECT * FROM couples     ORDER BY created_at')->fetchAll();
    $parentChild = $pdo->query('SELECT * FROM parent_child ORDER BY created_at')->fetchAll();
    respond(['persons' => $persons, 'couples' => $couples, 'parent_child' => $parentChild]);
}

// ── PERSONS ───────────────────────────────────────────────────────
function handlePersons() {
    global $pdo, $method, $id, $body;

    if ($method === 'GET' && $id === '') {
        respond($pdo->query('SELECT * FROM persons ORDER BY last_name, first_name')->fetchAll());
    }

    if ($method === 'GET' && $id !== '') {
        $st = $pdo->prepare('SELECT * FROM persons WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) respond(['error' => 'Not found'], 404);
        respond($row);
    }

    if ($method === 'POST') {
        $newId = uuid4();
        $pdo->prepare("
            INSERT INTO persons
              (id, first_name, last_name, maiden_name, gender,
               birth_date, birth_place, death_date, death_place,
               occupation, education, eye_color, hair_color, notes,
               canvas_x, canvas_y)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $newId,
            str($body, 'first_name'), str($body, 'last_name'),
            str($body, 'maiden_name'), str($body, 'gender'),
            str($body, 'birth_date'), str($body, 'birth_place'),
            str($body, 'death_date'), str($body, 'death_place'),
            str($body, 'occupation'), str($body, 'education'),
            str($body, 'eye_color'),  str($body, 'hair_color'),
            str($body, 'notes'),
            num($body, 'canvas_x'),   num($body, 'canvas_y'),
        ]);
        $st = $pdo->prepare('SELECT * FROM persons WHERE id = ?');
        $st->execute([$newId]);
        respond($st->fetch(), 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        if ($id === '') respond(['error' => 'id required'], 400);
        $pdo->prepare("
            UPDATE persons SET
              first_name=?, last_name=?, maiden_name=?, gender=?,
              birth_date=?, birth_place=?, death_date=?, death_place=?,
              occupation=?, education=?, eye_color=?, hair_color=?,
              notes=?, canvas_x=?, canvas_y=?
            WHERE id=?
        ")->execute([
            str($body,'first_name'), str($body,'last_name'),
            str($body,'maiden_name'), str($body,'gender'),
            str($body,'birth_date'), str($body,'birth_place'),
            str($body,'death_date'), str($body,'death_place'),
            str($body,'occupation'), str($body,'education'),
            str($body,'eye_color'),  str($body,'hair_color'),
            str($body,'notes'),
            num($body,'canvas_x'),   num($body,'canvas_y'),
            $id,
        ]);
        respond(['success' => true]);
    }

    if ($method === 'DELETE') {
        if ($id === '') respond(['error' => 'id required'], 400);
        $pdo->prepare('DELETE FROM persons WHERE id=?')->execute([$id]);
        respond(['success' => true]);
    }

    respond(['error' => 'Method not allowed'], 405);
}

// ── COUPLES ───────────────────────────────────────────────────────
function handleCouples() {
    global $pdo, $method, $id, $body;

    if ($method === 'GET' && $id === '') {
        respond($pdo->query('SELECT * FROM couples ORDER BY created_at')->fetchAll());
    }

    if ($method === 'POST') {
        $newId = uuid4();
        $pdo->prepare("
            INSERT INTO couples
              (id, person1_id, person2_id, rel_type,
               start_date, start_place, status, end_date, end_place, line_color)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $newId,
            str($body,'person1_id'), str($body,'person2_id'),
            str($body,'rel_type') ?: 'married',
            str($body,'start_date'), str($body,'start_place'),
            str($body,'status') ?: 'active',
            str($body,'end_date'), str($body,'end_place'),
            str($body,'line_color') ?: '#2563eb',
        ]);
        $st = $pdo->prepare('SELECT * FROM couples WHERE id=?');
        $st->execute([$newId]);
        respond($st->fetch(), 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        if ($id === '') respond(['error' => 'id required'], 400);
        $pdo->prepare("
            UPDATE couples SET
              rel_type=?, start_date=?, start_place=?,
              status=?, end_date=?, end_place=?, line_color=?
            WHERE id=?
        ")->execute([
            str($body,'rel_type') ?: 'married',
            str($body,'start_date'), str($body,'start_place'),
            str($body,'status') ?: 'active',
            str($body,'end_date'), str($body,'end_place'),
            str($body,'line_color') ?: '#2563eb',
            $id,
        ]);
        respond(['success' => true]);
    }

    if ($method === 'DELETE') {
        if ($id === '') respond(['error' => 'id required'], 400);
        $pdo->prepare('DELETE FROM couples WHERE id=?')->execute([$id]);
        respond(['success' => true]);
    }

    respond(['error' => 'Method not allowed'], 405);
}

// ── PARENT_CHILD ──────────────────────────────────────────────────
function handleParentChild() {
    global $pdo, $method, $id, $body;

    if ($method === 'GET') {
        respond($pdo->query('SELECT * FROM parent_child ORDER BY created_at')->fetchAll());
    }

    if ($method === 'POST') {
        $newId = uuid4();
        // Ignore duplicate — return existing row if constraint fires
        try {
            $pdo->prepare("
                INSERT INTO parent_child (id, parent_id, child_id, couple_id)
                VALUES (?,?,?,?)
            ")->execute([
                $newId,
                str($body,'parent_id'),
                str($body,'child_id'),
                str($body,'couple_id') ?: null,
            ]);
        } catch (PDOException $e) {
            // 23000 = integrity constraint (duplicate)
            if ($e->getCode() === '23000') {
                $st = $pdo->prepare('SELECT * FROM parent_child WHERE parent_id=? AND child_id=?');
                $st->execute([str($body,'parent_id'), str($body,'child_id')]);
                respond($st->fetch(), 200);
            }
            throw $e;
        }
        $st = $pdo->prepare('SELECT * FROM parent_child WHERE id=?');
        $st->execute([$newId]);
        respond($st->fetch(), 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        if ($id === '') respond(['error' => 'id required'], 400);
        $coupleId = array_key_exists('couple_id', $body)
            ? (str($body,'couple_id') ?: null)
            : false;
        if ($coupleId !== false) {
            $pdo->prepare('UPDATE parent_child SET couple_id=? WHERE id=?')
                ->execute([$coupleId, $id]);
        }
        respond(['success' => true]);
    }

    if ($method === 'DELETE') {
        if ($id === '') respond(['error' => 'id required'], 400);
        $pdo->prepare('DELETE FROM parent_child WHERE id=?')->execute([$id]);
        respond(['success' => true]);
    }

    respond(['error' => 'Method not allowed'], 405);
}

// ════════════════════════════════════════════════════════════════
// UTILITIES
// ════════════════════════════════════════════════════════════════

function respond(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Safely get a trimmed string value from an array, null if missing/empty */
function str(array $arr, string $key): ?string {
    $v = $arr[$key] ?? null;
    if ($v === null || $v === '') return null;
    return trim((string)$v);
}

/** Safely get a numeric value from an array, null if missing/non-numeric */
function num(array $arr, string $key): ?float {
    $v = $arr[$key] ?? null;
    if ($v === null || $v === '') return null;
    return is_numeric($v) ? (float)$v : null;
}

/** RFC 4122 v4 UUID */
function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
