<?php
declare(strict_types=1);
/*
 * GbR spike API: proves the chain browser -> PHP -> MySQL.
 *   GET  api.php?action=health              connection, versions, schema state
 *   POST api.php?action=migrate             apply pending db/*.sql, JSON {token}
 *   GET  api.php?action=accounts
 *   GET  api.php?action=bookings
 *   POST api.php?action=booking_add         JSON {date,soll,haben,amount,text,beleg}
 *   POST api.php?action=booking_delete      JSON {id}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
function body(): array {
    $d = json_decode(file_get_contents('php://input') ?: '{}', true);
    return is_array($d) ? $d : [];
}

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) out(500, ['ok' => false, 'error' => 'config.php fehlt – config.sample.php kopieren und ausfüllen']);
$cfg = require $cfgFile;

try {
    $pdo = new PDO(
        "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
        $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $e) {
    out(500, ['ok' => false, 'db' => false, 'error' => 'Datenbankverbindung fehlgeschlagen']);   // no details to the client
}

function ensureMigrationTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(100) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function migrationFiles(): array {
    $f = glob(__DIR__ . '/db/*.sql') ?: [];
    sort($f);
    return $f;
}

$action = $_GET['action'] ?? 'health';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {
    case 'health':
        ensureMigrationTable($pdo);
        $applied = $pdo->query("SELECT version FROM schema_migrations ORDER BY version")->fetchAll(PDO::FETCH_COLUMN);
        $all = array_map(fn($f) => basename($f), migrationFiles());
        out(200, [
            'ok' => true, 'db' => true,
            'php' => PHP_VERSION,
            'server' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'applied' => $applied,
            'pending' => array_values(array_diff($all, $applied)),
            'time' => date('c'),
        ]);

    case 'migrate':
        if ($method !== 'POST') out(405, ['ok' => false, 'error' => 'POST erwartet']);
        // token comes in the JSON body (UTF-8 safe); the header is only a fallback for scripts
        $expected = trim((string)($cfg['migrate_token'] ?? ''));
        if ($expected === '' || $expected === 'replace-with-a-long-random-string')
            out(500, ['ok' => false, 'error' => 'migrate_token in config.php ist nicht gesetzt']);
        $token = trim((string)(body()['token'] ?? ($_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? '')));
        if ($token === '') out(403, ['ok' => false, 'error' => 'Kein Token übermittelt']);
        if (!hash_equals($expected, $token)) out(403, ['ok' => false, 'error' => 'Token falsch']);
        ensureMigrationTable($pdo);
        $applied = $pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
        $done = [];
        foreach (migrationFiles() as $file) {
            $v = basename($file);
            if (in_array($v, $applied, true)) continue;
            // statements are separated by ";" at line end; DDL commits implicitly in MySQL, so one file = one step
            foreach (preg_split('/;\s*$/m', file_get_contents($file)) as $sql) {
                $sql = trim(preg_replace('/^\s*--.*$/m', '', $sql));
                if ($sql !== '') $pdo->exec($sql);
            }
            $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?)")->execute([$v]);
            $done[] = $v;
        }
        out(200, ['ok' => true, 'applied_now' => $done]);

    case 'accounts':
        out(200, ['ok' => true, 'accounts' => $pdo->query("SELECT nr,name,art FROM accounts ORDER BY nr")->fetchAll()]);

    case 'bookings':
        $rows = $pdo->query("SELECT id,no,booking_date AS date,soll,haben,amount,text,beleg,locked,clarify
                             FROM bookings ORDER BY booking_date DESC, no DESC")->fetchAll();
        foreach ($rows as &$r) { $r['amount'] = (float)$r['amount']; $r['locked'] = (bool)$r['locked']; $r['clarify'] = (bool)$r['clarify']; }
        out(200, ['ok' => true, 'bookings' => $rows]);

    case 'booking_add':
        if ($method !== 'POST') out(405, ['ok' => false, 'error' => 'POST erwartet']);
        $b = body();
        $date = (string)($b['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) out(400, ['ok' => false, 'error' => 'Datum ungültig']);
        $amount = round((float)($b['amount'] ?? 0), 2);
        if ($amount <= 0) out(400, ['ok' => false, 'error' => 'Betrag muss größer 0 sein']);
        $pdo->beginTransaction();
        $no = (int)$pdo->query("SELECT COALESCE(MAX(no),0)+1 FROM bookings FOR UPDATE")->fetchColumn();
        $st = $pdo->prepare("INSERT INTO bookings (no,booking_date,soll,haben,amount,text,beleg) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$no, $date, (string)$b['soll'], (string)$b['haben'], $amount,
                      mb_substr((string)($b['text'] ?? ''), 0, 255), mb_substr((string)($b['beleg'] ?? ''), 0, 60)]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        out(201, ['ok' => true, 'id' => $id, 'no' => $no]);

    case 'booking_delete':
        if ($method !== 'POST') out(405, ['ok' => false, 'error' => 'POST erwartet']);
        $id = (int)(body()['id'] ?? 0);
        $st = $pdo->prepare("DELETE FROM bookings WHERE id=? AND locked=0");
        $st->execute([$id]);
        out(200, ['ok' => true, 'deleted' => $st->rowCount()]);

    default:
        out(404, ['ok' => false, 'error' => 'Unbekannte Aktion']);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('gbr api: ' . $e->getMessage());
    $msg = str_contains($e->getMessage(), 'foreign key') ? 'Konto existiert nicht' : 'Datenbankfehler';
    out(500, ['ok' => false, 'error' => $msg]);
}
