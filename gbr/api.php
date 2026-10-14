<?php
declare(strict_types=1);
/*
 * GbR API – JSON in, JSON out. Writes are POST with a JSON body.
 *
 * Every request except health, migrate, login, logout, me and user_set needs a logged-in user
 * (session cookie, logout after 2 h without activity). Every POST must send the header "X-GBR: 1".
 *
 *  System     GET  health                     connection, schema_version (versions only when logged in)
 *             POST migrate        {token}     apply pending db/*.sql
 *  Anmeldung  POST login          {username,password}
 *             POST logout
 *             GET  me                         {user} or {user:null}
 *             POST password_change {old,new}
 *             POST user_set       {token,username,password,display_name?}   create user / reset password
 *  Konten     GET  accounts                   chart of accounts (+ how often used)
 *             POST account_save   {nr,name,art}
 *             POST account_delete {nr}        refused while the account is used
 *  Journal    GET  bookings       [&year=]    bookings, newest first
 *             GET  booking_years
 *             GET  booking_history &id=       change log of one booking
 *             POST booking_save   {id?,date,soll,haben,amount,text,beleg,clarify?,source?,asset_id?}
 *             POST booking_delete {id}        open bookings only
 *             POST booking_flag   {id,clarify?,irrelevant?}
 *             POST bookings_lock  {year}      Festschreiben (skips „Klären“)
 *             POST booking_storno {id,date?}  locked bookings only
 *             POST bank_import    {rows:[{date,soll,haben,amount,text,beleg?,bank_key}]}
 *  (booking_add is kept for the spike test page: booking_save without id)
 */
define('GBR_API', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/journal.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function migrationFiles(): array { $f = glob(__DIR__ . '/db/*.sql') ?: []; sort($f); return $f; }
function ensureMigrationTable(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$action = (string)($_GET['action'] ?? 'health');
$public = ['health', 'migrate', 'login', 'logout', 'me', 'user_set'];
try {
    requireAppHeader();
    if (!in_array($action, $public, true)) requireUser();

    switch ($action) {
    case 'health':
        ensureMigrationTable();
        $applied = db()->query("SELECT version FROM schema_migrations ORDER BY version")->fetchAll(PDO::FETCH_COLUMN);
        $latest = $applied ? end($applied) : null;    // schema version = number of the newest applied migration, e.g. "002"
        $info = ['ok' => true, 'db' => true,
            'schema_version' => $latest ? preg_replace('/^(\d+).*/', '$1', $latest) : '000',
            'pending' => array_values(array_diff(array_map('basename', migrationFiles()), $applied))];
        $loggedIn = false;
        try { $loggedIn = in_array('003_users.sql', $applied, true) && currentUser() !== null; } catch (ApiError) {}
        if ($loggedIn)                                 // software versions only for logged-in users
            $info += ['php' => PHP_VERSION, 'server' => db()->getAttribute(PDO::ATTR_SERVER_VERSION), 'applied' => $applied, 'time' => date('c')];
        out(200, $info);

    case 'migrate':
        requirePost();
        // token comes in the JSON body (UTF-8 safe); the header is only a fallback for scripts
        requireToken(['token' => body()['token'] ?? ($_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? '')]);
        ensureMigrationTable();
        $applied = db()->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
        $done = [];
        foreach (migrationFiles() as $file) {
            $v = basename($file);
            if (in_array($v, $applied, true)) continue;
            // statements end with ";" at line end; DDL commits implicitly in MySQL, so one file = one step
            foreach (preg_split('/;\s*$/m', file_get_contents($file)) as $sql) {
                $sql = trim(preg_replace('/^\s*--.*$/m', '', $sql));
                if ($sql !== '') db()->exec($sql);
            }
            db()->prepare("INSERT INTO schema_migrations (version) VALUES (?)")->execute([$v]);
            $done[] = $v;
        }
        out(200, ['ok' => true, 'applied_now' => $done]);

    /* ---- login ---- */
    case 'login':           requirePost(); out(200, ['ok' => true, 'user' => login(body())]);
    case 'logout':          requirePost(); logoutSession(); out(200, ['ok' => true]);
    case 'me':
        try { $u = currentUser(); } catch (ApiError) { $u = null; }
        out(200, ['ok' => true, 'user' => $u, 'idle_seconds' => idleSeconds()]);
    case 'password_change': requirePost(); passwordChange(body(), requireUser()); out(200, ['ok' => true]);
    case 'user_set':        requirePost(); out(200, ['ok' => true, 'user' => userSet(body())]);

    /* ---- chart of accounts ---- */
    case 'accounts':        out(200, ['ok' => true, 'accounts' => accountsList()]);
    case 'account_save':    requirePost(); out(200, ['ok' => true, 'account' => accountSave(body())]);
    case 'account_delete':  requirePost(); accountDelete(body()); out(200, ['ok' => true]);

    /* ---- journal ---- */
    case 'bookings':        out(200, ['ok' => true, 'bookings' => bookingsList(isset($_GET['year']) ? (string)$_GET['year'] : null)]);
    case 'booking_years':   out(200, ['ok' => true, 'years' => bookingYears()]);
    case 'booking_history': out(200, ['ok' => true, 'history' => bookingHistory(vId($_GET['id'] ?? 0))]);
    case 'booking_add':
    case 'booking_save':
        requirePost();
        $in = body();
        if ($action === 'booking_add') unset($in['id']);
        $isNew = empty($in['id']);
        $b = bookingSave($in);
        out($isNew ? 201 : 200, ['ok' => true, 'booking' => $b, 'id' => $b['id'], 'no' => $b['no']]);
    case 'booking_delete':  requirePost(); bookingDelete(body()); out(200, ['ok' => true, 'deleted' => 1]);
    case 'booking_flag':    requirePost(); out(200, ['ok' => true, 'booking' => bookingFlag(body())]);
    case 'bookings_lock':   requirePost(); out(200, ['ok' => true] + bookingsLock(body()));
    case 'booking_storno':  requirePost(); out(201, ['ok' => true, 'booking' => bookingStorno(body())]);
    case 'bank_import':     requirePost(); out(200, ['ok' => true] + bankImport(body()));

    default: throw new ApiError('Unbekannte Aktion', 404);
    }
} catch (ApiError $e) {
    rollbackIfOpen();
    out($e->status, ['ok' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    rollbackIfOpen();
    error_log('gbr api: ' . $e->getMessage());
    out(400, ['ok' => false, 'error' => dbErrorMessage($e)]);
} catch (Throwable $e) {
    rollbackIfOpen();
    error_log('gbr api: ' . $e->getMessage());
    out(500, ['ok' => false, 'error' => 'Interner Fehler']);
}
