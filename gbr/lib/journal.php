<?php
declare(strict_types=1);
/* Chart of accounts and journal: the bookkeeping rules of the GbR app, enforced on the server. */
if (!defined('GBR_API')) { http_response_code(404); exit; }

const BOOKING_COLS = "id, no, booking_date AS date, soll, haben, amount, text, beleg, source, locked, locked_at,
                      clarify, irrelevant, storno_of, asset_id, bank_key, created_at, updated_at";

function bookingRow(array $r): array {
    foreach (['id', 'no', 'storno_of', 'asset_id'] as $k) if ($r[$k] !== null) $r[$k] = (int)$r[$k];
    foreach (['locked', 'clarify', 'irrelevant'] as $k) $r[$k] = (bool)$r[$k];
    $r['amount'] = (float)$r['amount'];
    return $r;
}
function bookingGet(int $id, bool $forUpdate = false): array {
    $st = db()->prepare("SELECT " . BOOKING_COLS . " FROM bookings WHERE id=?" . ($forUpdate ? " FOR UPDATE" : ""));
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new ApiError('Buchung nicht gefunden', 404);
    return bookingRow($r);
}
/* every change to the journal is written to booking_log with the full state of the booking */
function bookingLog(int $id, string $action, ?array $snapshot = null): void {
    $snapshot ??= bookingGet($id);
    db()->prepare("INSERT INTO booking_log (booking_id, action, user_id, snapshot) VALUES (?,?,?,?)")
        ->execute([$id, $action, currentUserId(), json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
}
function nextBookingNo(): int {
    // the journal number is unique (UNIQUE KEY); reading the maximum inside the transaction is enough for this app
    return (int)db()->query("SELECT COALESCE(MAX(no),0)+1 FROM bookings FOR UPDATE")->fetchColumn();
}
function assertOpen(array $b): void {
    if ($b['locked']) throw new ApiError('Buchung #' . $b['no'] . ' ist festgeschrieben – nur per Storno korrigierbar', 409);
}

/* ---------------- accounts ---------------- */
function accountsList(): array {
    $rows = db()->query("SELECT a.nr, a.name, a.art,
                           (SELECT COUNT(*) FROM bookings b WHERE b.soll=a.nr OR b.haben=a.nr) AS used
                         FROM accounts a ORDER BY a.nr")->fetchAll();
    foreach ($rows as &$r) $r['used'] = (int)$r['used'];
    return $rows;
}
function accountSave(array $in): array {
    $nr = vText($in['nr'] ?? '', 10);
    if (!preg_match('/^\d{3,6}$/', $nr)) throw new ApiError('Kontonummer: 3–6 Ziffern');
    $name = vText($in['name'] ?? '', 120);
    if ($name === '') throw new ApiError('Kontobezeichnung fehlt');
    $art = (string)($in['art'] ?? '');
    if (!in_array($art, ['ertrag', 'aufwand', 'aktiv', 'passiv'], true)) throw new ApiError('Kontoart ungültig');
    db()->prepare("INSERT INTO accounts (nr,name,art) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), art=VALUES(art)")
        ->execute([$nr, $name, $art]);
    return ['nr' => $nr, 'name' => $name, 'art' => $art];
}
function accountDelete(array $in): void {
    $nr = vText($in['nr'] ?? '', 10);
    $st = db()->prepare("DELETE FROM accounts WHERE nr=?");
    $st->execute([$nr]);                 // FK refuses accounts that are still used in bookings or assets
    if (!$st->rowCount()) throw new ApiError('Konto nicht gefunden', 404);
}

/* ---------------- bookings ---------------- */
function bookingsList(?string $year): array {
    if ($year !== null && !preg_match('/^\d{4}$/', $year)) throw new ApiError('Jahr ungültig');
    $sql = "SELECT " . BOOKING_COLS . " FROM bookings" . ($year ? " WHERE booking_date BETWEEN ? AND ?" : "") .
           " ORDER BY booking_date DESC, no DESC";
    $st = db()->prepare($sql);
    $st->execute($year ? ["$year-01-01", "$year-12-31"] : []);
    return array_map('bookingRow', $st->fetchAll());
}
function bookingYears(): array {
    return array_map('intval', db()->query("SELECT DISTINCT YEAR(booking_date) y FROM bookings ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN));
}

/* create (no id) or update (id) a booking */
function bookingSave(array $in): array {
    $date = vDate($in['date'] ?? '');
    $soll = vText($in['soll'] ?? '', 10);
    $haben = vText($in['haben'] ?? '', 10);
    if ($soll === '' || $haben === '') throw new ApiError('Soll- und Habenkonto angeben');
    if ($soll === $haben) throw new ApiError('Soll und Haben dürfen nicht dasselbe Konto sein');
    $amount = vAmount($in['amount'] ?? null);
    $text = vText($in['text'] ?? '', 255);
    $beleg = vText($in['beleg'] ?? '', 60);
    $source = in_array($in['source'] ?? 'manual', ['manual', 'afa', 'import'], true) ? ($in['source'] ?? 'manual') : 'manual';
    $assetId = isset($in['asset_id']) && $in['asset_id'] !== null ? vId($in['asset_id']) : null;
    $pdo = db();
    $pdo->beginTransaction();
    if (!empty($in['id'])) {
        $b = bookingGet(vId($in['id']), true);
        assertOpen($b);
        $pdo->prepare("UPDATE bookings SET booking_date=?, soll=?, haben=?, amount=?, text=?, beleg=? WHERE id=?")
            ->execute([$date, $soll, $haben, $amount, $text, $beleg, $b['id']]);
        bookingLog($b['id'], 'update');
        $id = $b['id'];
    } else {
        $no = nextBookingNo();
        $pdo->prepare("INSERT INTO bookings (no, booking_date, soll, haben, amount, text, beleg, source, asset_id, clarify)
                       VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$no, $date, $soll, $haben, $amount, $text, $beleg, $source, $assetId, !empty($in['clarify']) ? 1 : 0]);
        $id = (int)$pdo->lastInsertId();
        bookingLog($id, 'create');
    }
    $pdo->commit();
    return bookingGet($id);
}
function bookingDelete(array $in): void {
    $pdo = db();
    $pdo->beginTransaction();
    $b = bookingGet(vId($in['id'] ?? 0), true);
    assertOpen($b);
    bookingLog($b['id'], 'delete', $b);
    $pdo->prepare("DELETE FROM bookings WHERE id=?")->execute([$b['id']]);
    $pdo->commit();
}
/* „Klären“ (clarify) and „privat“ (irrelevant) markers – only on open bookings */
function bookingFlag(array $in): array {
    $pdo = db();
    $pdo->beginTransaction();
    $b = bookingGet(vId($in['id'] ?? 0), true);
    assertOpen($b);
    foreach (['clarify' => 'clarify', 'irrelevant' => 'private'] as $field => $action) {
        if (!array_key_exists($field, $in)) continue;
        $pdo->prepare("UPDATE bookings SET $field=? WHERE id=?")->execute([$in[$field] ? 1 : 0, $b['id']]);
        bookingLog($b['id'], $action);
    }
    $pdo->commit();
    return bookingGet($b['id']);
}
/* Festschreiben: lock every open booking of a year except those marked „Klären“ */
function bookingsLock(array $in): array {
    $year = (string)($in['year'] ?? '');
    if (!preg_match('/^\d{4}$/', $year)) throw new ApiError('Jahr ungültig');
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT id, clarify FROM bookings WHERE locked=0 AND booking_date BETWEEN ? AND ? FOR UPDATE");
    $st->execute(["$year-01-01", "$year-12-31"]);
    $locked = 0; $held = 0;
    foreach ($st->fetchAll() as $r) {
        if ($r['clarify']) { $held++; continue; }
        $pdo->prepare("UPDATE bookings SET locked=1, locked_at=NOW() WHERE id=?")->execute([$r['id']]);
        bookingLog((int)$r['id'], 'lock');
        $locked++;
    }
    $pdo->commit();
    return ['locked' => $locked, 'held_clarify' => $held];
}
/* Storno: reverse a locked booking (Soll/Haben swapped), locked immediately, linked via storno_of */
function bookingStorno(array $in): array {
    $pdo = db();
    $pdo->beginTransaction();
    $b = bookingGet(vId($in['id'] ?? 0), true);
    if (!$b['locked']) throw new ApiError('Nur festgeschriebene Buchungen werden storniert – offene einfach ändern oder löschen', 409);
    if ($b['source'] === 'storno') throw new ApiError('Ein Storno kann nicht storniert werden', 409);
    $dup = $pdo->prepare("SELECT no FROM bookings WHERE storno_of=?");
    $dup->execute([$b['id']]);
    if ($n = $dup->fetchColumn()) throw new ApiError("Buchung #{$b['no']} ist bereits storniert (Storno #$n)", 409);
    $date = isset($in['date']) ? vDate($in['date']) : date('Y-m-d');
    $no = nextBookingNo();
    $pdo->prepare("INSERT INTO bookings (no, booking_date, soll, haben, amount, text, beleg, source, locked, locked_at, storno_of)
                   VALUES (?,?,?,?,?,?,?,'storno',1,NOW(),?)")
        ->execute([$no, $date, $b['haben'], $b['soll'], number_format($b['amount'], 2, '.', ''),
                   mb_substr('Storno #' . $b['no'] . ($b['text'] !== '' ? ' · ' . $b['text'] : ''), 0, 255), $b['beleg'], $b['id']]);
    $id = (int)$pdo->lastInsertId();
    bookingLog($id, 'storno');
    $pdo->commit();
    return bookingGet($id);
}
/* bank statement import: rows carry a bank_key; rows already imported are skipped, not duplicated */
function bankImport(array $in): array {
    $rows = $in['rows'] ?? null;
    if (!is_array($rows) || !$rows) throw new ApiError('Keine Buchungen übergeben');
    if (count($rows) > 2000) throw new ApiError('Zu viele Zeilen in einem Import (max. 2000)');
    $pdo = db();
    $pdo->beginTransaction();
    $added = []; $skipped = 0;
    $exists = $pdo->prepare("SELECT 1 FROM bookings WHERE bank_key=?");
    foreach (array_values($rows) as $i => $r) {
        try {
            $key = vText($r['bank_key'] ?? '', 190);
            if ($key === '') throw new ApiError('bank_key fehlt');
            $exists->execute([$key]);
            if ($exists->fetchColumn()) { $skipped++; continue; }
            $date = vDate($r['date'] ?? '');
            $soll = vText($r['soll'] ?? '', 10); $haben = vText($r['haben'] ?? '', 10);
            if ($soll === '' || $haben === '' || $soll === $haben) throw new ApiError('Konten ungültig');
            $no = nextBookingNo();
            $pdo->prepare("INSERT INTO bookings (no, booking_date, soll, haben, amount, text, beleg, source, bank_key)
                           VALUES (?,?,?,?,?,?,?,'bank',?)")
                ->execute([$no, $date, $soll, $haben, vAmount($r['amount'] ?? null), vText($r['text'] ?? '', 255), vText($r['beleg'] ?? '', 60), $key]);
            $id = (int)$pdo->lastInsertId();
            bookingLog($id, 'create');
            $added[] = $no;
        } catch (ApiError | PDOException $e) {
            $pdo->rollBack();                                    // all or nothing: the statement is imported completely or not at all
            $msg = $e instanceof PDOException ? dbErrorMessage($e) : $e->getMessage();
            throw new ApiError('Zeile ' . ($i + 1) . ': ' . $msg . ' – nichts importiert');
        }
    }
    $pdo->commit();
    return ['added' => count($added), 'skipped_duplicates' => $skipped, 'numbers' => $added];
}
function bookingHistory(int $id): array {
    $st = db()->prepare("SELECT l.action, l.snapshot, l.at, u.username FROM booking_log l LEFT JOIN users u ON u.id = l.user_id
                         WHERE l.booking_id=? ORDER BY l.id");
    $st->execute([$id]);
    return array_map(fn($r) => ['action' => $r['action'], 'at' => $r['at'], 'user' => $r['username'],
                                'snapshot' => json_decode($r['snapshot'], true)], $st->fetchAll());
}
