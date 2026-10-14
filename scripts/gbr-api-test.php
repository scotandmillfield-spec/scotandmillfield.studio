<?php
declare(strict_types=1);
/*
 * Automated test of the GbR API (login, accounts, journal) against a running server and an EMPTY test database.
 * Never point this at the production database – it creates users, locks and deletes bookings.
 *
 *   php -S 127.0.0.1:8090 -t gbr          (gbr/config.php for the test database with
 *                                          'allow_http' => true and 'session_idle' => 3)
 *   php scripts/gbr-api-test.php http://127.0.0.1:8090 <migrate-token>
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8090', '/');
$token = $argv[2] ?? '';
if ($token === '') { echo "Aufruf: php scripts/gbr-api-test.php <url> <migrate-token>\n"; exit(2); }
$fails = 0; $passes = 0;
$cookie = '';                                   // session cookie of the simulated browser

/* $appHeader=false sends a POST without "X-GBR: 1" (like a foreign website would) */
function call(string $action, ?array $body = null, string $query = '', string $method = '', bool $appHeader = true): array {
    global $base, $cookie;
    $h = "Content-Type: application/json\r\n" . ($appHeader ? "X-GBR: 1\r\n" : '') . ($cookie !== '' ? "Cookie: $cookie\r\n" : '');
    $opts = ['http' => ['ignore_errors' => true, 'method' => $method ?: ($body === null ? 'GET' : 'POST'),
             'header' => $h, 'content' => $body === null ? '' : json_encode($body)]];
    $raw = file_get_contents("$base/api.php?action=$action$query", false, stream_context_create($opts));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    foreach ($http_response_header ?? [] as $line)            // keep the session cookie like a browser
        if (preg_match('/^Set-Cookie:\s*(GBRSESSID=[^;]*)/i', $line, $c)) $cookie = $c[1] === 'GBRSESSID=deleted' || $c[1] === 'GBRSESSID=' ? '' : $c[1];
    return ['status' => (int)($m[1] ?? 0)] + (json_decode((string)$raw, true) ?? ['raw' => $raw]);
}
function check(string $name, bool $cond, mixed $detail = null): void {
    global $fails, $passes;
    if ($cond) { $passes++; echo "  ✓ $name\n"; }
    else { $fails++; echo "  ✗ $name" . ($detail !== null ? '  → ' . json_encode($detail, JSON_UNESCAPED_UNICODE) : '') . "\n"; }
}
function ok(string $name, array $r): array { check($name, ($r['ok'] ?? false) === true, $r); return $r; }
function refused(string $name, array $r, string $expect = ''): void {
    check("$name → abgewiesen" . (isset($r['error']) ? " („{$r['error']}“)" : ''),
          ($r['ok'] ?? true) === false && ($expect === '' || str_contains($r['error'] ?? '', $expect)), $r);
}

echo "System\n";
ok('Migration', call('migrate', ['token' => $token]));
$h = ok('Health', call('health'));
if (($h['ok'] ?? false) !== true) { echo "\nServer oder Datenbank nicht erreichbar – Test abgebrochen.\n"; exit(2); }
check('Schema-Version ≥ 003', ($h['schema_version'] ?? '000') >= '003', $h);
check('keine ausstehenden Migrationen', ($h['pending'] ?? ['x']) === [], $h);
check('ohne Anmeldung keine Softwareversionen', !isset($h['php']) && !isset($h['server']), $h);
refused('Migration mit falschem Token', call('migrate', ['token' => 'falsch']), 'Token');
refused('POST ohne X-GBR-Header', call('migrate', ['token' => $token], '', '', false), 'abgelehnt');

echo "Anmeldung\n";
$pw = 'Test-Passwort-123';
refused('Benutzer anlegen ohne Token', call('user_set', ['username' => 'tester', 'password' => $pw]), 'Token');
refused('Benutzer anlegen, Passwort zu kurz', call('user_set', ['token' => $token, 'username' => 'tester', 'password' => 'kurz']), 'zu kurz');
refused('Benutzername mit Leerzeichen', call('user_set', ['token' => $token, 'username' => 'te ster', 'password' => $pw]), 'Benutzername');
ok('Benutzer anlegen (mit Token)', call('user_set', ['token' => $token, 'username' => 'tester', 'display_name' => 'Test Ärger', 'password' => $pw]));
$r = call('accounts');
check('ohne Anmeldung: Konten → 401', $r['status'] === 401 && ($r['ok'] ?? true) === false, $r);
$r = call('booking_save', ['date' => '2031-01-01', 'soll' => '4200', 'haben' => '1200', 'amount' => 1]);
check('ohne Anmeldung: Buchen → 401', $r['status'] === 401, $r);
check('me ohne Anmeldung → user null', array_key_exists('user', $me = call('me')) && $me['user'] === null, $me);
refused('falsches Passwort', call('login', ['username' => 'tester', 'password' => 'falsch-falsch']), 'falsch');
refused('unbekannter Benutzer', call('login', ['username' => 'niemand', 'password' => $pw]), 'falsch');
$l = ok('Anmelden', call('login', ['username' => 'tester', 'password' => $pw]));
check('Anzeigename mit Umlaut', ($l['user']['display_name'] ?? '') === 'Test Ärger', $l);
check('Session-Cookie gesetzt', $cookie !== '');
$me = call('me');
check('me → tester', ($me['user']['username'] ?? '') === 'tester' && ($me['idle_seconds'] ?? 0) > 0, $me);
check('angemeldet: Health mit Versionen', isset(call('health')['php']));
refused('unbekannte Aktion', call('gibtsnicht'));
refused('Schreibaktion per GET', call('booking_save'), 'POST');
refused('angemeldet, aber POST ohne X-GBR-Header', call('account_save', ['nr' => '4100', 'name' => 'x', 'art' => 'aufwand'], '', '', false), 'abgelehnt');

echo "Kontenplan\n";
$acc = ok('Konten lesen', call('accounts'));
check('Startkonten vorhanden (1200, 8000)', count(array_filter($acc['accounts'], fn($a) => in_array($a['nr'], ['1200', '8000'], true))) === 2);
ok('Konto anlegen', call('account_save', ['nr' => '4100', 'name' => 'Testkonto', 'art' => 'aufwand']));
ok('Konto umbenennen', call('account_save', ['nr' => '4100', 'name' => 'Testkonto neu', 'art' => 'aufwand']));
refused('Kontonummer mit Buchstaben', call('account_save', ['nr' => '41a', 'name' => 'x', 'art' => 'aufwand']));
refused('Kontoart ungültig', call('account_save', ['nr' => '4101', 'name' => 'x', 'art' => 'egal']));
ok('unbenutztes Konto löschen', call('account_delete', ['nr' => '4100']));

echo "Buchen\n";
$y = '2031';                                   // own year so the test does not collide with other data
$b1 = ok('Buchung anlegen', call('booking_save', ['date' => "$y-03-01", 'soll' => '4200', 'haben' => '1200', 'amount' => 120, 'text' => 'Miete März · Grüße', 'beleg' => 'B1']));
$id1 = $b1['booking']['id'];
check('Umlaute bleiben erhalten', ($b1['booking']['text'] ?? '') === 'Miete März · Grüße', $b1);
check('Quelle manual, offen', ($b1['booking']['source'] ?? '') === 'manual' && $b1['booking']['locked'] === false, $b1);
refused('Betrag 0', call('booking_save', ['date' => "$y-03-02", 'soll' => '4200', 'haben' => '1200', 'amount' => 0]), 'größer 0');
refused('negativer Betrag', call('booking_save', ['date' => "$y-03-02", 'soll' => '4200', 'haben' => '1200', 'amount' => -5]), 'größer 0');
refused('Soll = Haben', call('booking_save', ['date' => "$y-03-02", 'soll' => '1200', 'haben' => '1200', 'amount' => 5]), 'dasselbe Konto');
refused('unbekanntes Konto', call('booking_save', ['date' => "$y-03-02", 'soll' => '9999', 'haben' => '1200', 'amount' => 5]), 'Konto');
refused('ungültiges Datum', call('booking_save', ['date' => "$y-02-30", 'soll' => '4200', 'haben' => '1200', 'amount' => 5]), 'Datum');
$u = ok('offene Buchung ändern', call('booking_save', ['id' => $id1, 'date' => "$y-03-01", 'soll' => '4200', 'haben' => '1200', 'amount' => 125.5, 'text' => 'Miete März (korrigiert)']));
check('Betrag geändert auf 125,50', abs(($u['booking']['amount'] ?? 0) - 125.5) < 1e-9, $u);
$b2 = ok('zweite Buchung', call('booking_save', ['date' => "$y-03-05", 'soll' => '4920', 'haben' => '1200', 'amount' => 0.10]));
$b3 = ok('dritte Buchung', call('booking_save', ['date' => "$y-03-06", 'soll' => '4920', 'haben' => '1200', 'amount' => 0.20]));
check('fortlaufende Journalnummern', $b3['no'] === $b2['no'] + 1, [$b2['no'], $b3['no']]);
ok('„privat“ markieren', call('booking_flag', ['id' => $b2['booking']['id'], 'irrelevant' => true]));

echo "Klären & Festschreiben\n";
$k = ok('Buchung auf „Klären“', call('booking_save', ['date' => "$y-03-09", 'soll' => '4964', 'haben' => '1200', 'amount' => 89.9, 'text' => 'Unklare Lastschrift', 'clarify' => true]));
$lock = ok('Festschreiben', call('bookings_lock', ['year' => $y]));
check('3 festgeschrieben, 1 „Klären“ übersprungen', ($lock['locked'] ?? 0) === 3 && ($lock['held_clarify'] ?? 0) === 1, $lock);
refused('festgeschriebene Buchung ändern', call('booking_save', ['id' => $id1, 'date' => "$y-03-01", 'soll' => '4200', 'haben' => '1200', 'amount' => 1]), 'festgeschrieben');
refused('festgeschriebene Buchung löschen', call('booking_delete', ['id' => $id1]), 'festgeschrieben');
refused('festgeschriebene Buchung markieren', call('booking_flag', ['id' => $id1, 'clarify' => true]), 'festgeschrieben');
ok('„Klären“-Buchung bleibt änderbar', call('booking_save', ['id' => $k['booking']['id'], 'date' => "$y-03-09", 'soll' => '4970', 'haben' => '1200', 'amount' => 89.9, 'text' => 'Bankgebühr (geklärt)']));
ok('„Geklärt“', call('booking_flag', ['id' => $k['booking']['id'], 'clarify' => false]));
$lock2 = ok('erneut festschreiben', call('bookings_lock', ['year' => $y]));
check('jetzt 1 festgeschrieben, 0 übersprungen', ($lock2['locked'] ?? 0) === 1 && ($lock2['held_clarify'] ?? 1) === 0, $lock2);

echo "Storno\n";
$open = ok('offene Buchung für Storno-Test', call('booking_save', ['date' => "$y-04-01", 'soll' => '4200', 'haben' => '1200', 'amount' => 10]));
refused('offene Buchung stornieren', call('booking_storno', ['id' => $open['booking']['id']]), 'festgeschrieben');
$st = ok('festgeschriebene Buchung stornieren', call('booking_storno', ['id' => $id1, 'date' => "$y-04-02"]));
$s = $st['booking'];
check('Storno: Konten getauscht, gleicher Betrag, festgeschrieben, verknüpft',
      $s['soll'] === '1200' && $s['haben'] === '4200' && abs($s['amount'] - 125.5) < 1e-9 && $s['locked'] && $s['storno_of'] === $id1 && $s['source'] === 'storno', $s);
refused('zweites Storno derselben Buchung', call('booking_storno', ['id' => $id1]), 'bereits storniert');
refused('Storno stornieren', call('booking_storno', ['id' => $s['id']]), 'Storno');

echo "Bankimport\n";
$rows = [
    ['date' => "$y-05-02", 'soll' => '1200', 'haben' => '8000', 'amount' => 500.44, 'text' => 'Royalties', 'bank_key' => "$y-05-02|+|500.44|Label"],
    ['date' => "$y-05-03", 'soll' => '4970', 'haben' => '1200', 'amount' => 4.90, 'text' => 'Kontoführung', 'bank_key' => "$y-05-03|-|4.90|Bank"],
];
$imp = ok('Kontoauszug importieren', call('bank_import', ['rows' => $rows]));
check('2 neu, 0 doppelt', ($imp['added'] ?? 0) === 2 && ($imp['skipped_duplicates'] ?? 1) === 0, $imp);
$imp2 = ok('denselben Auszug nochmal', call('bank_import', ['rows' => $rows]));
check('0 neu, 2 als doppelt erkannt', ($imp2['added'] ?? 1) === 0 && ($imp2['skipped_duplicates'] ?? 0) === 2, $imp2);
$before = count(call('bookings', null, "&year=$y")['bookings']);
refused('Auszug mit fehlerhafter Zeile', call('bank_import', ['rows' => [
    ['date' => "$y-05-10", 'soll' => '1200', 'haben' => '8000', 'amount' => 1, 'bank_key' => 'neu-1'],
    ['date' => "$y-05-11", 'soll' => '1200', 'haben' => '8000', 'amount' => 0, 'bank_key' => 'neu-2']]]), 'nichts importiert');
check('nichts halb importiert', count(call('bookings', null, "&year=$y")['bookings']) === $before);

echo "Löschen, Protokoll, Lesen\n";
ok('offene Buchung löschen', call('booking_delete', ['id' => $open['booking']['id']]));
$hist = ok('Protokoll der gelöschten Buchung', call('booking_history', null, '&id=' . $open['booking']['id']));
check('Protokoll: create + delete', array_column($hist['history'], 'action') === ['create', 'delete'], $hist);
check('Protokoll nennt den Benutzer', array_unique(array_column($hist['history'], 'user')) === ['tester'], $hist);
$hist1 = call('booking_history', null, "&id=$id1");
check('Protokoll Buchung 1: create, update, lock', array_column($hist1['history'], 'action') === ['create', 'update', 'lock'], $hist1);
refused('benutztes Konto löschen', call('account_delete', ['nr' => '4200']), 'verwendet');
$yrs = ok('Buchungsjahre', call('booking_years'));
check("Jahr $y enthalten", in_array((int)$y, $yrs['years'], true), $yrs);
$list = call('bookings', null, "&year=$y")['bookings'];
$sum4920 = array_sum(array_map(fn($b) => $b['soll'] === '4920' ? $b['amount'] : 0, $list));
check('Cent-genau: 0,10 + 0,20 = 0,30', abs($sum4920 - 0.30) < 1e-9, $sum4920);

echo "Passwort, Sperre, Abmelden, Zeitablauf\n";
refused('Passwort ändern mit falschem alten', call('password_change', ['old' => 'falsch', 'new' => 'Neues-Passwort-456']), 'falsch');
refused('neues Passwort zu kurz', call('password_change', ['old' => $pw, 'new' => 'kurz']), 'zu kurz');
ok('Passwort ändern', call('password_change', ['old' => $pw, 'new' => 'Neues-Passwort-456']));
ok('nach Passwortwechsel weiter angemeldet', call('accounts'));
ok('Abmelden', call('logout', []));
$r = call('accounts');
check('nach Abmelden → 401', $r['status'] === 401, $r);
refused('altes Passwort gilt nicht mehr', call('login', ['username' => 'tester', 'password' => $pw]), 'falsch');
ok('Anmelden mit neuem Passwort', call('login', ['username' => 'tester', 'password' => 'Neues-Passwort-456']));
ok('Abmelden', call('logout', []));
ok('zweiter Benutzer für Sperrtest', call('user_set', ['token' => $token, 'username' => 'sperre', 'password' => $pw]));
for ($i = 1; $i < 5; $i++) call('login', ['username' => 'sperre', 'password' => "falsch-$i"]);
$r = call('login', ['username' => 'sperre', 'password' => 'falsch-5']);
check('5. Fehlversuch → gesperrt (429)', $r['status'] === 429, $r);
$r = call('login', ['username' => 'sperre', 'password' => $pw]);
check('gesperrt: auch richtiges Passwort → 429', $r['status'] === 429, $r);
ok('anderer Benutzer nicht betroffen', call('login', ['username' => 'tester', 'password' => 'Neues-Passwort-456']));
ok('Zurücksetzen mit Token hebt Sperre auf', call('user_set', ['token' => $token, 'username' => 'sperre', 'password' => $pw]));
ok('Anmelden nach Zurücksetzen', call('login', ['username' => 'sperre', 'password' => $pw]));
$idle = (int)(call('me')['idle_seconds'] ?? 7200);
if ($idle <= 10) {
    sleep($idle + 2);
    $r = call('accounts');
    check("nach {$idle}s Inaktivität → abgemeldet", $r['status'] === 401 && str_contains($r['error'] ?? '', 'abgelaufen'), $r);
} else echo "  · Zeitablauf nicht geprüft (session_idle = $idle s; für den Test 'session_idle' => 3 setzen)\n";

echo "\n" . ($fails ? "✗ $fails von " . ($fails + $passes) . " Prüfungen fehlgeschlagen\n" : "✓ alle $passes Prüfungen bestanden\n");
exit($fails ? 1 : 0);
