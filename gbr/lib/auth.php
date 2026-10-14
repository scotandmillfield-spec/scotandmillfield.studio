<?php
declare(strict_types=1);
/* Login, sessions and users. Native PHP sessions with a hardened cookie; every API call except the
   few public ones requires a logged-in user. Only loaded by api.php. */
if (!defined('GBR_API')) { http_response_code(404); exit; }

const MAX_FAILED = 5;          // failed logins before the account is locked
const LOCK_MINUTES = 15;
const MIN_PASSWORD = 10;

function idleSeconds(): int { return (int)(config()['session_idle'] ?? 7200); }   // log out after 2 h without activity

function startSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('GBRSESSID');
    ini_set('session.use_strict_mode', '1');            // never accept session ids the server did not create
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)idleSeconds());
    session_set_cookie_params([
        'lifetime' => 0,                                 // cookie ends with the browser session
        'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',   // only /gbr/
        'secure'   => $https || empty(config()['allow_http']),   // HTTPS only (allow_http just for local tests)
        'httponly' => true,                              // not readable by JavaScript
        'samesite' => 'Strict',                          // not sent by other websites
    ]);
    session_start();
}

/* the logged-in user, or null; enforces the inactivity timeout */
function currentUser(): ?array {
    startSession();
    if (empty($_SESSION['uid'])) return null;
    if (time() - (int)($_SESSION['last'] ?? 0) > idleSeconds()) {
        logoutSession();
        throw new ApiError('Sitzung abgelaufen – bitte neu anmelden', 401);
    }
    $_SESSION['last'] = time();
    $st = db()->prepare("SELECT id, username, display_name FROM users WHERE id=? AND active=1");
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u) { logoutSession(); return null; }
    $u['id'] = (int)$u['id'];
    return $u;
}
function requireUser(): array {
    $u = currentUser();
    if (!$u) throw new ApiError('Bitte anmelden', 401);
    return $u;
}
function currentUserId(): ?int {
    return (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['uid'])) ? (int)$_SESSION['uid'] : null;
}
function logoutSession(): void {
    startSession();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600] + array_intersect_key($p, array_flip(['path', 'domain', 'secure', 'httponly', 'samesite'])));
    session_destroy();
}

/* POST requests must carry X-GBR: 1 – a custom header another website cannot send (CSRF protection) */
function requireAppHeader(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_SERVER['HTTP_X_GBR'] ?? '') !== '1')
        throw new ApiError('Anfrage abgelehnt', 403);
}

function login(array $in): array {
    $username = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');
    if ($username === '' || $password === '') throw new ApiError('Benutzername und Passwort angeben');
    $st = db()->prepare("SELECT id, username, display_name, password_hash, active, failed_count, locked_until FROM users WHERE username=?");
    $st->execute([$username]);
    $u = $st->fetch();
    // unknown user: still spend the time of one hash check so response time does not reveal valid names
    if (!$u) { password_hash($password, PASSWORD_DEFAULT); throw new ApiError('Benutzername oder Passwort falsch', 401); }
    if ($u['locked_until'] !== null && strtotime($u['locked_until']) > time())
        throw new ApiError('Zu viele Fehlversuche – Konto bis ' . date('H:i', strtotime($u['locked_until'])) . ' Uhr gesperrt', 429);
    if (!$u['active'] || !password_verify($password, $u['password_hash'])) {
        $fails = (int)$u['failed_count'] + 1;
        $lock = $fails >= MAX_FAILED ? date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60) : null;
        db()->prepare("UPDATE users SET failed_count=?, locked_until=? WHERE id=?")->execute([$lock ? 0 : $fails, $lock, $u['id']]);
        if ($lock) throw new ApiError('Zu viele Fehlversuche – Konto für ' . LOCK_MINUTES . ' Minuten gesperrt', 429);
        throw new ApiError('Benutzername oder Passwort falsch', 401);
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT))
        db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    db()->prepare("UPDATE users SET failed_count=0, locked_until=NULL, last_login_at=NOW() WHERE id=?")->execute([$u['id']]);
    startSession();
    session_regenerate_id(true);                         // new session id after login (session fixation)
    $_SESSION = ['uid' => (int)$u['id'], 'last' => time()];
    return ['id' => (int)$u['id'], 'username' => $u['username'], 'display_name' => $u['display_name']];
}

function validPassword(string $p): void {
    if (mb_strlen($p) < MIN_PASSWORD) throw new ApiError('Passwort zu kurz – mindestens ' . MIN_PASSWORD . ' Zeichen');
}
function passwordChange(array $in, array $user): void {
    $st = db()->prepare("SELECT password_hash FROM users WHERE id=?");
    $st->execute([$user['id']]);
    if (!password_verify((string)($in['old'] ?? ''), (string)$st->fetchColumn())) throw new ApiError('Bisheriges Passwort falsch', 403);
    $new = (string)($in['new'] ?? '');
    validPassword($new);
    db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    session_regenerate_id(true);
}

/* administration with the migrate token (from config.php): create a user or reset a password */
function requireToken(array $in): void {
    $expected = trim((string)(config()['migrate_token'] ?? ''));
    if ($expected === '' || $expected === 'replace-with-a-long-random-string') throw new ApiError('migrate_token in config.php ist nicht gesetzt', 500);
    $token = trim((string)($in['token'] ?? ''));
    if ($token === '') throw new ApiError('Kein Token übermittelt', 403);
    if (!hash_equals($expected, $token)) throw new ApiError('Token falsch', 403);
}
function userSet(array $in): array {
    requireToken($in);
    $username = trim((string)($in['username'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) throw new ApiError('Benutzername: 3–60 Zeichen, nur Buchstaben, Ziffern, . _ -');
    $password = (string)($in['password'] ?? '');
    validPassword($password);
    $name = mb_substr(trim((string)($in['display_name'] ?? $username)), 0, 120);
    db()->prepare("INSERT INTO users (username, display_name, password_hash) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), display_name=VALUES(display_name),
                                           failed_count=0, locked_until=NULL, active=1")
        ->execute([$username, $name, password_hash($password, PASSWORD_DEFAULT)]);
    return ['username' => $username, 'display_name' => $name];
}
