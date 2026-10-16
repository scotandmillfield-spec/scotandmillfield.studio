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
/* multipart POST (file uploads): $files = [field => [filename, content]], $payload goes as JSON in "payload" */
function callFiles(string $action, array $payload, array $files): array {
    global $base, $cookie;
    $bd = '----gbrtest' . bin2hex(random_bytes(6));
    $body = "--$bd\r\nContent-Disposition: form-data; name=\"payload\"\r\n\r\n" . json_encode($payload) . "\r\n";
    foreach ($files as $field => [$name, $content])
        $body .= "--$bd\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$name\"\r\nContent-Type: application/octet-stream\r\n\r\n$content\r\n";
    $body .= "--$bd--\r\n";
    $opts = ['http' => ['ignore_errors' => true, 'method' => 'POST', 'content' => $body,
             'header' => "Content-Type: multipart/form-data; boundary=$bd\r\nX-GBR: 1\r\nCookie: $cookie\r\n"]];
    $raw = file_get_contents("$base/api.php?action=$action", false, stream_context_create($opts));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    return ['status' => (int)($m[1] ?? 0)] + (json_decode((string)$raw, true) ?? ['raw' => $raw]);
}
function fetchRaw(string $query): array {
    global $base, $cookie;
    $raw = file_get_contents("$base/api.php?$query", false, stream_context_create(['http' => ['header' => "Cookie: $cookie\r\n", 'ignore_errors' => true]]));
    return [$raw, $http_response_header];
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

echo "Übernahme aus Browser-Backup, Einstellungen\n";
$legacy = [
    ['id' => 'b1', 'no' => 1, 'date' => '2030-01-10', 'soll' => '4200', 'haben' => '1200', 'amount' => 300, 'text' => 'Miete Jan', 'beleg' => 'R1', 'source' => 'manual', 'locked' => true],
    ['id' => 'b2', 'no' => 2, 'date' => '2030-01-15', 'soll' => '1200', 'haben' => '8000', 'amount' => 12.5, 'text' => 'Royalties', 'source' => 'fyrst', 'fkey' => '2030-01-15|+|12.50|Label', 'locked' => true, 'clarify' => true],
    ['id' => 'b3', 'no' => 4, 'date' => '2030-02-01', 'soll' => '1200', 'haben' => '4200', 'amount' => 300, 'text' => 'Storno #1 · Miete Jan', 'source' => 'storno', 'locked' => true, 'stornoOf' => 1],
    ['id' => 'b4', 'no' => 3, 'date' => '2030-01-20', 'soll' => '4920', 'haben' => '1210', 'amount' => 9.99, 'text' => 'Telefon privat', 'irrelevant' => true, 'clarify' => true],
    ['id' => 'b5', 'no' => 5, 'date' => '2030-02-03', 'soll' => '1200', 'haben' => '1200', 'amount' => 0.68, 'text' => 'Rücklastschrift', 'source' => 'fyrst', 'locked' => true],
];
$legacyAcc = [['nr' => '1200', 'name' => 'Bank', 'art' => 'aktiv'], ['nr' => '4925', 'name' => 'Porto', 'art' => 'aufwand']];
refused('Übernahme mit fehlerhafter Buchung', call('journal_import', ['accounts' => [], 'bookings' => [['no' => 1, 'date' => '2030-13-01', 'soll' => '4200', 'haben' => '1200', 'amount' => 1]]]), 'nichts übernommen');
check('danach Journal noch leer', call('bookings')['bookings'] === []);
$ji = ok('Journal aus Backup übernehmen', call('journal_import', ['accounts' => $legacyAcc, 'bookings' => $legacy]));
check('4 Buchungen, 3 festgeschrieben', ($ji['bookings'] ?? 0) === 4 && ($ji['locked'] ?? 0) === 3, $ji);
check('Soll = Haben übersprungen und gemeldet (#5)', array_column($ji['skipped'] ?? [], 'no') === [5], $ji);
$bk = []; foreach (call('bookings', null, '&year=2030')['bookings'] as $b) $bk[$b['no']] = $b;
check('Nummern bleiben erhalten (1–4)', count($bk) === 4 && isset($bk[1], $bk[2], $bk[3], $bk[4]), array_keys($bk));
check('Storno verknüpft mit Buchung #1', ($bk[4]['storno_of'] ?? 0) === $bk[1]['id'] && $bk[4]['source'] === 'storno', $bk[4] ?? null);
check('Bankbuchung: Quelle bank, Schlüssel übernommen', $bk[2]['source'] === 'bank' && $bk[2]['bank_key'] === '2030-01-15|+|12.50|Label', $bk[2]);
check('festgeschrieben ⇒ nicht „Klären“', $bk[2]['locked'] && !$bk[2]['clarify'], $bk[2]);
check('offene Buchung: Klären + privat erhalten', !$bk[3]['locked'] && $bk[3]['clarify'] && $bk[3]['irrelevant'], $bk[3]);
check('Konto aus Backup angelegt (4925)', in_array('4925', array_column(call('accounts')['accounts'], 'nr'), true));
refused('zweite Übernahme', call('journal_import', ['accounts' => [], 'bookings' => $legacy]), 'nicht leer');
$bi = ok('Bankzeile aus Backup erneut importieren', call('bank_import', ['rows' => [['date' => '2030-01-15', 'soll' => '1200', 'haben' => '8000', 'amount' => 12.5, 'text' => 'x', 'bank_key' => '2030-01-15|+|12.50|Label']]]));
check('als Dublette erkannt', ($bi['added'] ?? 1) === 0 && ($bi['skipped_duplicates'] ?? 0) === 1, $bi);
$set = ok('Einstellungen lesen', call('settings'));
check('Briefkopf noch leer', array_key_exists('issuer', $set['settings'] ?? []) && $set['settings']['issuer'] === null, $set);
$iss = ['name' => 'Test GbR', 'addr' => 'Teststr. 1 | 12345 Musterstadt', 'iban' => 'DE00 0000', 'bic' => 'TESTDEFF', 'taxNo' => '000/000', 'place' => 'Mülheim', 'signoff' => 'A & B', 'evil' => '<x>'];
$sv = ok('Briefkopf speichern', call('setting_save', ['key' => 'issuer', 'value' => $iss]));
check('unbekanntes Feld verworfen', !isset($sv['value']['evil']) && $sv['value']['place'] === 'Mülheim', $sv);
check('Briefkopf gespeichert', (call('settings')['settings']['issuer']['name'] ?? '') === 'Test GbR');
refused('unbekannte Einstellung', call('setting_save', ['key' => 'admin', 'value' => ['x' => 1]]), 'Unbekannte');

echo "Anlagen\n";
check('Anlagenliste leer', (ok('Anlagen lesen', call('assets'))['assets'] ?? null) === []);
$legacyAssets = [
    ['id' => 'a1', 'name' => 'Studiomonitore', 'cost' => 2200, 'years' => 7, 'date' => '2022-09-01', 'afaKonto' => '4830', 'assetKonto' => '0420'],
    ['id' => 'a2', 'name' => 'Raum-in-Raum', 'cost' => 12124.11, 'years' => 7, 'date' => '2022-09-01', 'afaKonto' => '4830'],
];
refused('Übernahme mit unbekanntem Konto', call('assets_import', ['assets' => [['name' => 'x', 'cost' => 1, 'years' => 3, 'date' => '2024-01-01', 'afaKonto' => '9999']]]), 'nichts übernommen');
check('danach weiter leer', call('assets')['assets'] === []);
$ai = ok('Anlagen aus Browser übernehmen', call('assets_import', ['assets' => $legacyAssets]));
check('2 übernommen', ($ai['assets'] ?? 0) === 2, $ai);
$al = call('assets')['assets'];
check('Werte korrekt (Kosten, Jahre, Datum, Konten, Standard 0420)', count($al) === 2 && abs($al[1]['cost'] - 12124.11) < 1e-9 && $al[1]['years'] === 7
      && $al[1]['date'] === '2022-09-01' && $al[1]['afa_account'] === '4830' && $al[1]['asset_account'] === '0420', $al);
refused('zweite Übernahme', call('assets_import', ['assets' => $legacyAssets]), 'bereits Anlagen');
$na = ok('Anlage anlegen', call('asset_save', ['name' => 'Laptop', 'cost' => 1499.99, 'years' => 3, 'date' => '2026-02-10', 'afa_account' => '4830', 'asset_account' => '0420']));
refused('Nutzungsdauer 0', call('asset_save', ['name' => 'x', 'cost' => 10, 'years' => 0, 'date' => '2026-01-01', 'afa_account' => '4830', 'asset_account' => '0420']), 'Nutzungsdauer');
refused('Kosten 0', call('asset_save', ['name' => 'x', 'cost' => 0, 'years' => 3, 'date' => '2026-01-01', 'afa_account' => '4830', 'asset_account' => '0420']), 'größer 0');
refused('unbekanntes Konto', call('asset_save', ['name' => 'x', 'cost' => 10, 'years' => 3, 'date' => '2026-01-01', 'afa_account' => '9999', 'asset_account' => '0420']), 'Verweis');
$up = ok('Anlage ändern', call('asset_save', ['id' => $na['asset']['id'], 'name' => 'Laptop Pro', 'cost' => 1499.99, 'years' => 3, 'date' => '2026-02-10', 'afa_account' => '4830', 'asset_account' => '0420']));
check('Name geändert', ($up['asset']['name'] ?? '') === 'Laptop Pro', $up);
ok('Anlage löschen', call('asset_delete', ['id' => $na['asset']['id']]));
check('2 Anlagen übrig', count(call('assets')['assets']) === 2);
refused('gelöschte Anlage nochmal löschen', call('asset_delete', ['id' => $na['asset']['id']]), 'nicht gefunden');

echo "Abrechnungen, Rechnungen, PDF-Archiv\n";
check('Abrechnungen leer', (ok('Abrechnungen lesen', call('statements'))['statements'] ?? null) === []);
$legacyStmts = [
    ['id' => 'label a|100', 'label' => 'Label A B.V.', 'stmtNo' => '100', 'year' => 2024, 'half' => 'H2', 'periodStart' => '01.07.2024', 'periodEnd' => '31.12.2024', 'payable' => 25.01, 'currency' => '€', 'status' => 'paid', 'carriedInto' => 'label a|101', 'linkedOnly' => true],
    ['id' => 'label a|101', 'label' => 'Label A B.V.', 'stmtNo' => '101', 'year' => 2025, 'half' => 'H1', 'payable' => 48.93, 'currency' => '€', 'status' => 'paid', 'carryover' => 25.01,
     'invoice' => ['no' => 'SM-2025-001', 'date' => '15.08.2025', 'recipient' => 'Label A B.V.', 'greeting' => 'Team', 'total' => 73.94,
                   'lines' => [['kind' => 'main', 'stmtId' => 'label a|101', 'desc' => 'Royalties 101', 'amount' => 48.93], ['kind' => 'extra', 'desc' => 'Carryover', 'amount' => 25.01]]]],
    ['id' => 'manual|label b|x', 'label' => 'Label B', 'stmtNo' => '', 'periodText' => 'Q3 2025', 'year' => 2025, 'half' => '', 'sort' => 202509, 'payable' => 12, 'currency' => '£', 'status' => 'carry', 'source' => 'manual'],
];
refused('Übernahme mit ungültigem Rechnungsdatum', call('invoices_import', ['statements' => [array_merge($legacyStmts[1], ['invoice' => array_merge($legacyStmts[1]['invoice'], ['date' => '2025-08-15'])])]]), 'nichts übernommen');
$ii = ok('Abrechnungen aus Browser übernehmen', call('invoices_import', ['statements' => $legacyStmts, 'labelAddresses' => ['label a b v' => "Street 1\n1234 Town", 'label c' => 'Somewhere']]));
check('3 Abrechnungen, 1 Rechnung, 2 Anschriften', ($ii['statements'] ?? 0) === 3 && ($ii['invoices'] ?? 0) === 1 && ($ii['addresses'] ?? 0) === 2, $ii);
refused('zweite Übernahme', call('invoices_import', ['statements' => $legacyStmts]), 'bereits Abrechnungen');
$L = call('statements'); $S = []; foreach ($L['statements'] as $x) $S[$x['stmtNo'] ?: $x['periodText']] = $x;
check('Carryover-Verknüpfung erhalten (100 → 101, nur zugeordnet)', ($S['100']['carriedInto'] ?? '') === $S['101']['id'] && $S['100']['linkedOnly'] === true, $S['100'] ?? null);
check('Rechnung erhalten (Nummer, Datum, 2 Positionen, Summe)', ($S['101']['invoice']['no'] ?? '') === 'SM-2025-001' && $S['101']['invoice']['date'] === '15.08.2025'
      && count($S['101']['invoice']['lines']) === 2 && abs($S['101']['invoice']['total'] - 73.94) < 1e-9, $S['101'] ?? null);
check('manuelle Abrechnung: Zeitraum, Währung, Quelle', ($S['Q3 2025']['currency'] ?? '') === '£' && $S['Q3 2025']['source'] === 'manual' && $S['Q3 2025']['sort'] === 202509, $S['Q3 2025'] ?? null);
check('Anschrift zum Label', str_contains((string)($L['labelAddresses']['label a b v'] ?? ''), '1234 Town'), $L['labelAddresses']);
$im = ok('PDF-Abrechnung importieren', call('statement_import', ['label' => 'Label A B.V.', 'stmtNo' => '102', 'year' => 2025, 'half' => 'H2', 'payable' => 30.5, 'currency' => '€', 'periodStart' => '01.07.2025', 'periodEnd' => '31.12.2025']));
$im2 = ok('dieselbe Abrechnung erneut importieren', call('statement_import', ['label' => 'Label A B.V.', 'stmtNo' => '102', 'year' => 2025, 'half' => 'H2', 'payable' => 31.5, 'currency' => '€']));
check('aktualisiert statt doppelt', $im2['id'] === $im['id'] && $im2['updated'] === true, $im2);
$sid = $im['id']; $carryId = $S['Q3 2025']['id'];
$pdf = base64_encode("%PDF-1.4\n% Test-Rechnung\n%%EOF");
refused('Rechnungsnummer schon vergeben', call('invoice_save', ['statement_id' => $sid, 'no' => 'SM-2025-001', 'date' => '01.02.2026', 'lines' => [['kind' => 'main', 'desc' => 'x', 'amount' => 1]]]), 'bereits vergeben');
refused('Rechnungsdatum ungültig', call('invoice_save', ['statement_id' => $sid, 'no' => 'SM-2026-001', 'date' => '31.02.2026', 'lines' => [['kind' => 'main', 'desc' => 'x', 'amount' => 1]]]), 'Rechnungsdatum');
refused('Rechnung ohne Positionen', call('invoice_save', ['statement_id' => $sid, 'no' => 'SM-2026-001', 'date' => '01.02.2026', 'lines' => []]), 'ohne Positionen');
refused('kein gültiges PDF', call('invoice_save', ['statement_id' => $sid, 'no' => 'SM-2026-001', 'date' => '01.02.2026', 'lines' => [['kind' => 'main', 'desc' => 'x', 'amount' => 1]], 'pdf' => base64_encode('kein pdf')]), 'PDF ungültig');
$iv = ok('Rechnung mit Carryover und PDF speichern', call('invoice_save', ['statement_id' => $sid, 'no' => 'SM-2026-001', 'date' => '01.02.2026', 'recipient' => 'Label A B.V.',
      'address' => "Street 2\n9999 City", 'greeting' => 'Hello', 'lines' => [['kind' => 'main', 'stmtId' => $sid, 'desc' => 'Royalties 102', 'amount' => 31.5],
      ['kind' => 'carry', 'stmtId' => $carryId, 'desc' => 'Carryover Q3', 'amount' => 12]], 'pdf' => $pdf]));
check('Summe 43,50, PDF archiviert', abs(($iv['total'] ?? 0) - 43.5) < 1e-9 && ($iv['pdf_id'] ?? 0) > 0, $iv);
$L = call('statements'); $S = []; foreach ($L['statements'] as $x) $S[$x['stmtNo'] ?: $x['periodText']] = $x;
check('Carryover hängt an der Rechnung', ($S['Q3 2025']['carriedInto'] ?? '') === $sid && $S['Q3 2025']['linkedOnly'] === false, $S['Q3 2025']);
check('neue Anschrift gespeichert', str_contains((string)($L['labelAddresses']['label a b v'] ?? ''), '9999 City'));
check('PDF in der Liste', count($S['102']['pdfs'] ?? []) === 1, $S['102']);
$raw = file_get_contents("$base/api.php?action=invoice_pdf&statement=$sid", false, stream_context_create(['http' => ['header' => "Cookie: $cookie\r\n", 'ignore_errors' => true]]));
check('archiviertes PDF abrufbar und unverändert', $raw === base64_decode($pdf) && preg_grep('/^X-Content-SHA256: ' . hash('sha256', $raw) . '$/i', $http_response_header), $http_response_header);
ok('bezahlt', call('statement_status', ['id' => $sid, 'status' => 'paid']));
$S2 = array_column(call('statements')['statements'], null, 'id');
check('Carryover wird mit bezahlt', $S2[$carryId]['status'] === 'paid', $S2[$carryId]);
ok('wieder offen', call('statement_status', ['id' => $sid, 'status' => 'open']));
check('Carryover zurück auf carry', array_column(call('statements')['statements'], null, 'id')[$carryId]['status'] === 'carry');
ok('Carryover wieder öffnen (nimmt ihn von der Rechnung)', call('statement_status', ['id' => $carryId, 'status' => 'open']));
$S3 = array_column(call('statements')['statements'], null, 'id');
check('Carryover-Position entfernt, Verknüpfung gelöst', $S3[$carryId]['carriedInto'] === null && count($S3[$sid]['invoice']['lines']) === 1, $S3[$sid]);
ok('manuell zuordnen', call('carry_assign', ['id' => $carryId, 'host_id' => $S['101']['id']]));
check('zugeordnet (linkedOnly)', array_column(call('statements')['statements'], null, 'id')[$carryId]['linkedOnly'] === true);
ok('Zuordnung aufheben', call('carry_unassign', ['id' => $carryId]));
$ms = ok('manuelle Abrechnung anlegen', call('statement_manual_save', ['label' => 'Label B', 'periodText' => '01.2026', 'year' => 2026, 'sort' => 202601, 'payable' => 9.5, 'currency' => '€', 'status' => 'open']));
ok('manuelle Abrechnung ändern', call('statement_manual_save', ['id' => $ms['id'], 'label' => 'Label B', 'periodText' => '02.2026', 'year' => 2026, 'sort' => 202602, 'payable' => 10, 'currency' => '€', 'status' => 'open']));
refused('ohne Betrag', call('statement_manual_save', ['label' => 'Label B', 'periodText' => '03.2026', 'year' => 2026, 'currency' => '€']), 'Betrag');
ok('Abrechnung mit Rechnung löschen', call('statement_delete', ['id' => $sid]));
$cnt = (int)(call('statements')['statements'] ? count(call('statements')['statements']) : 0);
check('gelöscht (4 übrig)', $cnt === 4, $cnt);
$raw2 = file_get_contents("$base/api.php?action=invoice_pdf&id=" . $iv['pdf_id'], false, stream_context_create(['http' => ['header' => "Cookie: $cookie\r\n", 'ignore_errors' => true]]));
check('PDF bleibt im Archiv', $raw2 === base64_decode($pdf));

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

echo "Belege & Kontoauszüge\n";
$auszug = "%PDF-1.4\n% Kontoauszug Mai\n%%EOF";
$bi3 = ok('Kontoauszug erneut importieren, diesmal mit PDF', callFiles('bank_import', ['rows' => array_map(fn($r) => $r + ['file' => 0], $rows)], ['file0' => ['Auszug_05.pdf', $auszug]]));
check('0 neu, 2 doppelt – aber beide mit dem Auszug verbunden', ($bi3['added'] ?? 1) === 0 && ($bi3['linked'] ?? 0) === 2 && ($bi3['documents'] ?? 0) === 1, $bi3);
$bk = array_column(call('bookings', null, "&year=$y")['bookings'], null, 'text');
check('Buchung „Royalties“ zeigt den Auszug', ($bk['Royalties']['docs'][0]['kind'] ?? '') === 'bank' && $bk['Royalties']['docs'][0]['name'] === 'Auszug_05.pdf', $bk['Royalties']['docs'] ?? null);
$bi4 = ok('noch einmal mit demselben PDF', callFiles('bank_import', ['rows' => array_map(fn($r) => $r + ['file' => 0], $rows)], ['file0' => ['Auszug_05.pdf', $auszug]]));
check('nichts doppelt verbunden', ($bi4['linked'] ?? 1) === 0, $bi4);
$beleg = "%PDF-1.7\n% Rechnung Bankgebühr\n%%EOF";
$kf = $bk['Kontoführung']['id'];
$up = ok('Beleg zu offener Buchung hochladen', callFiles('document_upload', ['booking_id' => $kf, 'kind' => 'beleg'], ['file' => ['Rechnung Bank.pdf', $beleg]]));
check('verbunden', ($up['linked'] ?? false) === true && $up['document']['mime'] === 'application/pdf', $up);
$up2 = ok('dieselbe Datei nochmal', callFiles('document_upload', ['kind' => 'beleg'], ['file' => ['kopie.pdf', $beleg]]));
check('wird nicht doppelt gespeichert', $up2['document']['id'] === $up['document']['id'], $up2);
refused('Textdatei als Beleg', callFiles('document_upload', ['booking_id' => $kf], ['file' => ['x.html', '<script>alert(1)</script>']]), 'Nur PDF');
$png = ok('Foto (PNG) als Beleg', callFiles('document_upload', ['booking_id' => $kf], ['file' => ['quittung.png', "\x89PNG\r\n\x1A\n" . str_repeat("\0", 20)]]));
[$raw, $hdr] = fetchRaw('action=document&id=' . $up['document']['id']);
check('Beleg abrufbar, unverändert, als PDF', $raw === $beleg && preg_grep('/^Content-Type: application\/pdf/i', $hdr) && preg_grep('/^X-Content-SHA256: ' . hash('sha256', $beleg) . '/i', $hdr), $hdr);
[$raw, $hdr] = fetchRaw('action=document&id=' . $png['document']['id']);
check('Foto als image/png', (bool)preg_grep('/^Content-Type: image\/png/i', $hdr), $hdr);
ok('Beleg von offener Buchung lösen', call('document_unlink', ['booking_id' => $kf, 'document_id' => $png['document']['id']]));
ok('ungenutztes Dokument löschen', call('document_delete', ['id' => $png['document']['id']]));
refused('verbundenes Dokument löschen', call('document_delete', ['id' => $up['document']['id']]), 'noch mit Buchungen');
ok('Beleg zusätzlich an festgeschriebene Buchung hängen', call('document_link', ['booking_id' => $id1, 'document_id' => $up['document']['id']]));
refused('Beleg von festgeschriebener Buchung lösen', call('document_unlink', ['booking_id' => $id1, 'document_id' => $up['document']['id']]), 'festgeschrieben');
$hk = array_column(call('booking_history', null, "&id=$kf")['history'], 'action');
check('Protokoll: attach … detach', in_array('attach', $hk, true) && in_array('detach', $hk, true), $hk);
$dl = ok('Archivliste', call('documents'));
check('Archiv: Auszug + Beleg, mit Buchungsnummern', count($dl['documents']) === 2 && count($dl['documents'][0]['bookings']) >= 1, $dl);
refused('Dokument ohne Anmeldung', (function () { global $cookie; $c = $cookie; $cookie = ''; $r = call('documents'); $cookie = $c; return $r; })(), 'anmelden');

echo "Löschen, Protokoll, Lesen\n";
ok('offene Buchung löschen', call('booking_delete', ['id' => $open['booking']['id']]));
$hist = ok('Protokoll der gelöschten Buchung', call('booking_history', null, '&id=' . $open['booking']['id']));
check('Protokoll: create + delete', array_column($hist['history'], 'action') === ['create', 'delete'], $hist);
check('Protokoll nennt den Benutzer', array_unique(array_column($hist['history'], 'user')) === ['tester'], $hist);
$hist1 = call('booking_history', null, "&id=$id1");
check('Protokoll Buchung 1: create, update, lock, attach', array_column($hist1['history'], 'action') === ['create', 'update', 'lock', 'attach'], $hist1);
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
