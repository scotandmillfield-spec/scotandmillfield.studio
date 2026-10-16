<?php
declare(strict_types=1);
/* Shared helpers for the GbR API: configuration, database connection, JSON in/out,
   input validation and readable error messages. Only loaded by api.php. */
if (!defined('GBR_API')) { http_response_code(404); exit; }

final class ApiError extends Exception {
    public function __construct(string $message, public readonly int $status = 400) { parent::__construct($message); }
}

function out(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
function body(): array {
    static $b = null;
    if ($b === null) {
        // file uploads come as multipart/form-data with the JSON in the field "payload"
        $raw = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')
             ? (string)($_POST['payload'] ?? '{}') : (file_get_contents('php://input') ?: '{}');
        $d = json_decode($raw, true);
        $b = is_array($d) ? $d : [];
    }
    return $b;
}
function config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) throw new ApiError('config.php fehlt – config.sample.php kopieren und ausfüllen', 500);
        $cfg = require $file;
    }
    return $cfg;
}
/* the open connection, if any (used to roll back after errors without connecting just for that) */
final class Conn { public static ?PDO $pdo = null; }
function rollbackIfOpen(): void { if (Conn::$pdo?->inTransaction()) Conn::$pdo->rollBack(); }

function db(): PDO {
    $pdo = &Conn::$pdo;
    if ($pdo === null) {
        $c = config();
        try {
            $pdo = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException) {
            throw new ApiError('Datenbankverbindung fehlgeschlagen', 500);   // no details to the client
        }
    }
    return $pdo;
}
function requirePost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new ApiError('POST erwartet', 405);
}

/* ---- validation ---- */
function vDate(mixed $v, string $what = 'Datum'): string {
    $s = (string)$v;
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) throw new ApiError("$what ungültig (JJJJ-MM-TT erwartet)");
    return $s;
}
function vAmount(mixed $v): string {
    if (!is_numeric($v)) throw new ApiError('Betrag ungültig');
    $a = round((float)$v, 2);
    if ($a <= 0) throw new ApiError('Betrag muss größer 0 sein');
    if ($a >= 1e10) throw new ApiError('Betrag zu groß');
    return number_format($a, 2, '.', '');           // exact string for DECIMAL
}
function vText(mixed $v, int $max): string { return mb_substr(trim((string)($v ?? '')), 0, $max); }
function vId(mixed $v): int {
    $i = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($i === false) throw new ApiError('ID ungültig');
    return $i;
}

/* ---- database errors in plain German ---- */
function dbErrorMessage(PDOException $e): string {
    $code = (int)($e->errorInfo[1] ?? 0);
    return match ($code) {
        1062 => 'Eintrag existiert bereits',
        1451 => 'Wird noch verwendet und kann nicht gelöscht werden',
        1452 => 'Verweis ungültig (z. B. Konto existiert nicht)',
        3819, 4025 => 'Unzulässige Daten (Prüfregel der Datenbank)',
        1153, 1301 => 'Datei zu groß für die Datenbank (max_allowed_packet)',
        default => 'Datenbankfehler',
    };
}
