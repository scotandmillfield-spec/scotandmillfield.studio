<?php
declare(strict_types=1);
/* Royalty statements, invoices, label addresses and the invoice PDF archive.
   The list is returned in the shape the app works with (statement objects with an embedded invoice),
   every change goes through one of the functions below and the app reloads the list afterwards. */
if (!defined('GBR_API')) { http_response_code(404); exit; }

const STATUSES = ['open', 'paid', 'carry'];
const MAX_PDF_BYTES = 8 * 1024 * 1024;

/* same as normKey() in the app: lower case, everything but a–z/0–9 becomes a space */
function labelKey(string $name): string { return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($name))); }

/* label id for a name (created on first use) */
function labelId(string $name): int {
    $name = vText($name, 160);
    $key = labelKey($name);
    if ($key === '') throw new ApiError('Label fehlt');
    $st = db()->prepare("SELECT id FROM labels WHERE name_key=?");
    $st->execute([$key]);
    if ($id = $st->fetchColumn()) return (int)$id;
    db()->prepare("INSERT INTO labels (name, name_key) VALUES (?,?)")->execute([$name, $key]);
    return (int)db()->lastInsertId();
}
function vCurrency(mixed $v): string {
    $c = vText($v ?? '€', 8);
    return $c === '' ? '€' : $c;
}
function vMoneyOrNull(mixed $v): ?string {
    if ($v === null || $v === '') return null;
    if (!is_numeric($v)) throw new ApiError('Betrag ungültig');
    return number_format(round((float)$v, 2), 2, '.', '');
}
function periodSort(int $year, ?string $half): int { return $year * 100 + ($half === 'H1' ? 6 : ($half === 'H2' ? 12 : 0)); }
function vYear(mixed $v): int {
    $y = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1990, 'max_range' => 2100]]);
    if ($y === false) throw new ApiError('Jahr ungültig');
    return $y;
}
function vHalf(mixed $v): ?string { return in_array($v, ['H1', 'H2'], true) ? $v : null; }
/* "dd.mm.yyyy" (as typed in the invoice) → Y-m-d */
function deDate(string $s): string {
    $d = DateTime::createFromFormat('!d.m.Y', trim($s));
    if (!$d || $d->format('d.m.Y') !== trim($s)) throw new ApiError('Rechnungsdatum ungültig (TT.MM.JJJJ erwartet)');
    return $d->format('Y-m-d');
}
function statementRow(int $id, bool $forUpdate = false): array {
    $st = db()->prepare("SELECT * FROM statements WHERE id=?" . ($forUpdate ? " FOR UPDATE" : ""));
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new ApiError('Abrechnung nicht gefunden', 404);
    return $r;
}

/* ---------------- read ---------------- */
function statementsAll(): array {
    $pdo = db();
    $labels = [];
    foreach ($pdo->query("SELECT id, name, name_key, address FROM labels")->fetchAll() as $l) $labels[(int)$l['id']] = $l;
    $inv = [];
    foreach ($pdo->query("SELECT * FROM invoices")->fetchAll() as $i) $inv[(int)$i['statement_id']] = $i + ['lines' => []];
    $byInvoice = [];
    foreach ($inv as $sid => $i) $byInvoice[(int)$i['id']] = $sid;
    foreach ($pdo->query("SELECT * FROM invoice_lines ORDER BY invoice_id, pos")->fetchAll() as $l) {
        $sid = $byInvoice[(int)$l['invoice_id']] ?? null;
        if ($sid === null) continue;
        $inv[$sid]['lines'][] = ['kind' => $l['kind'],
            'stmtId' => $l['kind'] === 'main' ? (string)$sid : ($l['carry_statement_id'] !== null ? (string)$l['carry_statement_id'] : null),
            'desc' => $l['description'], 'amount' => (float)$l['amount']];
    }
    $pdfs = [];
    foreach ($pdo->query("SELECT id, statement_id, invoice_no, bytes, created_at FROM invoice_pdfs WHERE statement_id IS NOT NULL ORDER BY id")->fetchAll() as $p)
        $pdfs[(int)$p['statement_id']][] = ['id' => (int)$p['id'], 'no' => $p['invoice_no'], 'bytes' => (int)$p['bytes'], 'at' => $p['created_at']];
    $out = [];
    foreach ($pdo->query("SELECT * FROM statements ORDER BY period_sort, id")->fetchAll() as $s) {
        $id = (int)$s['id'];
        $o = ['id' => (string)$id, 'label' => $labels[(int)$s['label_id']]['name'] ?? '?', 'stmtNo' => $s['statement_no'],
              'year' => (int)$s['period_year'], 'half' => $s['period_half'] ?? '', 'periodText' => $s['period_text'], 'sort' => (int)$s['period_sort'],
              'periodStart' => $s['period_start'] ?? '', 'periodEnd' => $s['period_end'] ?? '',
              'payable' => $s['payable'] !== null ? (float)$s['payable'] : null, 'currency' => $s['currency'],
              'status' => $s['status'], 'carryover' => (float)$s['carryover_stated'], 'source' => $s['source'],
              'carriedInto' => $s['carried_into'] !== null ? (string)$s['carried_into'] : null, 'linkedOnly' => (bool)$s['link_only'],
              'pdfs' => $pdfs[$id] ?? []];
        if (isset($inv[$id])) {
            $i = $inv[$id];
            $o['invoice'] = ['no' => $i['invoice_no'], 'date' => date('d.m.Y', strtotime($i['invoice_date'])), 'intro' => $i['intro'] ?? '',
                             'recipient' => $i['recipient_name'], 'greeting' => $i['greeting'], 'lines' => $i['lines'], 'total' => (float)$i['total']];
        }
        $out[] = $o;
    }
    $addr = [];
    foreach ($labels as $l) if ($l['address'] !== null && $l['address'] !== '') $addr[$l['name_key']] = $l['address'];
    return ['statements' => $out, 'labelAddresses' => (object)$addr];
}

/* ---------------- statements ---------------- */
/* from a royalty PDF: re-importing the same statement updates its figures, status/links/invoice stay */
function statementImport(array $in): array {
    $label = vText($in['label'] ?? '', 160);
    $labelId = labelId($label);
    $stmtNo = vText($in['stmtNo'] ?? '', 60);
    $year = vYear($in['year'] ?? null);
    $half = vHalf($in['half'] ?? null);
    $key = mb_substr(labelKey($label) . '|' . ($stmtNo !== '' ? $stmtNo : $year . '-' . ($half ?? '')), 0, 190);
    $vals = [$labelId, $stmtNo, trim(($half ?? '') . ' ' . $year), $year, $half, periodSort($year, $half),
             vText($in['periodStart'] ?? '', 20) ?: null, vText($in['periodEnd'] ?? '', 20) ?: null,
             vMoneyOrNull($in['payable'] ?? null), vMoneyOrNull($in['carryover'] ?? 0) ?? '0.00', vCurrency($in['currency'] ?? '€')];
    $st = db()->prepare("SELECT id FROM statements WHERE import_key=?");
    $st->execute([$key]);
    if ($id = $st->fetchColumn()) {
        db()->prepare("UPDATE statements SET label_id=?, statement_no=?, period_text=?, period_year=?, period_half=?, period_sort=?,
                       period_start=?, period_end=?, payable=?, carryover_stated=?, currency=? WHERE id=?")->execute([...$vals, $id]);
        return ['id' => (string)$id, 'updated' => true];
    }
    db()->prepare("INSERT INTO statements (label_id, statement_no, period_text, period_year, period_half, period_sort, period_start, period_end,
                   payable, carryover_stated, currency, source, import_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pdf',?)")->execute([...$vals, $key]);
    return ['id' => (string)db()->lastInsertId(), 'updated' => false];
}
/* labels without an importable statement */
function statementManualSave(array $in): array {
    $labelId = labelId(vText($in['label'] ?? '', 160));
    $periodText = vText($in['periodText'] ?? '', 40);
    if ($periodText === '') throw new ApiError('Zeitraum fehlt');
    $year = vYear($in['year'] ?? null);
    $half = vHalf($in['half'] ?? null);
    $sort = filter_var($in['sort'] ?? null, FILTER_VALIDATE_INT) ?: periodSort($year, $half);
    $payable = vMoneyOrNull($in['payable'] ?? null);
    if ($payable === null) throw new ApiError('Betrag fehlt');
    $status = in_array($in['status'] ?? 'open', ['open', 'carry'], true) ? $in['status'] ?? 'open' : 'open';
    $vals = [$labelId, vText($in['stmtNo'] ?? '', 60), $periodText, $year, $half, $sort, $payable, vCurrency($in['currency'] ?? '€')];
    if (!empty($in['id'])) {
        $s = statementRow(vId($in['id']));
        db()->prepare("UPDATE statements SET label_id=?, statement_no=?, period_text=?, period_year=?, period_half=?, period_sort=?, payable=?, currency=?,
                       status=IF(status<>'paid' AND carried_into IS NULL, ?, status) WHERE id=?")->execute([...$vals, $status, $s['id']]);
        return ['id' => (string)$s['id']];
    }
    db()->prepare("INSERT INTO statements (label_id, statement_no, period_text, period_year, period_half, period_sort, payable, currency, status, source)
                   VALUES (?,?,?,?,?,?,?,?,?,'manual')")->execute([...$vals, $status]);
    return ['id' => (string)db()->lastInsertId()];
}
/* take a carryover off the invoice it was put on */
function unlinkCarry(array $c): void {
    if ($c['carried_into'] !== null)
        db()->prepare("DELETE l FROM invoice_lines l JOIN invoices i ON i.id=l.invoice_id
                       WHERE i.statement_id=? AND l.kind='carry' AND l.carry_statement_id=?")->execute([$c['carried_into'], $c['id']]);
    db()->prepare("UPDATE statements SET carried_into=NULL, link_only=0 WHERE id=?")->execute([$c['id']]);
}
/* open / paid / carry; carryovers included in the invoice follow its payment state */
function statementStatus(array $in): void {
    $status = (string)($in['status'] ?? '');
    if (!in_array($status, STATUSES, true)) throw new ApiError('Status ungültig');
    $pdo = db();
    $pdo->beginTransaction();
    $s = statementRow(vId($in['id'] ?? 0), true);
    $pdo->prepare("UPDATE statements SET status=? WHERE id=?")->execute([$status, $s['id']]);
    if ($status === 'paid') $pdo->prepare("UPDATE statements SET status='paid' WHERE carried_into=?")->execute([$s['id']]);
    else $pdo->prepare("UPDATE statements SET status='carry' WHERE carried_into=? AND status='paid'")->execute([$s['id']]);
    if ($status === 'open' && $s['carried_into'] !== null) unlinkCarry($s);     // reopening takes it out of the invoice
    $pdo->commit();
}
/* record which (already issued) invoice contains a carryover – no new PDF */
function carryAssign(array $in): void {
    $pdo = db();
    $pdo->beginTransaction();
    $c = statementRow(vId($in['id'] ?? 0), true);
    $h = statementRow(vId($in['host_id'] ?? 0), true);
    if ($c['id'] === $h['id']) throw new ApiError('Eine Abrechnung kann nicht sich selbst zugeordnet werden');
    if ($h['status'] === 'carry') throw new ApiError('Ziel ist selbst ein Carryover');
    $pdo->prepare("UPDATE statements SET carried_into=?, link_only=1, status=IF(?='paid','paid',status) WHERE id=?")->execute([$h['id'], $h['status'], $c['id']]);
    $pdo->commit();
}
function carryUnassign(array $in): void {
    $pdo = db();
    $pdo->beginTransaction();
    $c = statementRow(vId($in['id'] ?? 0), true);
    unlinkCarry($c);
    $pdo->prepare("UPDATE statements SET status='carry' WHERE id=?")->execute([$c['id']]);
    $pdo->commit();
}
/* delete a statement (its invoice record goes with it; archived PDFs stay) */
function statementDelete(array $in): void {
    $pdo = db();
    $pdo->beginTransaction();
    $s = statementRow(vId($in['id'] ?? 0), true);
    $pdo->prepare("UPDATE statements SET carried_into=NULL, link_only=0, status=IF(status='paid','carry',status) WHERE carried_into=?")->execute([$s['id']]);
    $pdo->prepare("DELETE l FROM invoice_lines l JOIN invoices i ON i.id=l.invoice_id WHERE i.statement_id=?")->execute([$s['id']]);
    if ($s['carried_into'] !== null) unlinkCarry($s);
    $pdo->prepare("DELETE FROM invoice_lines WHERE carry_statement_id=?")->execute([$s['id']]);   // still listed on another invoice
    $pdo->prepare("DELETE FROM statements WHERE id=?")->execute([$s['id']]);
    $pdo->commit();
}

/* ---------------- invoices ---------------- */
/* save the invoice of a statement (create or edit), its lines, the label address, the carryover links
   and – if sent – the generated PDF into the archive */
function invoiceSave(array $in): array {
    $pdo = db();
    $pdo->beginTransaction();
    $s = statementRow(vId($in['statement_id'] ?? 0), true);
    $no = vText($in['no'] ?? '', 40);
    if ($no === '') throw new ApiError('Rechnungsnummer fehlt');
    $dup = $pdo->prepare("SELECT s.statement_no, l.name FROM invoices i JOIN statements s ON s.id=i.statement_id JOIN labels l ON l.id=s.label_id
                          WHERE i.invoice_no=? AND i.statement_id<>?");
    $dup->execute([$no, $s['id']]);
    if ($d = $dup->fetch()) throw new ApiError("Rechnungsnummer $no ist bereits vergeben ({$d['name']}" . ($d['statement_no'] !== '' ? ", Statement {$d['statement_no']}" : '') . ')', 409);
    $date = deDate((string)($in['date'] ?? ''));
    $lines = $in['lines'] ?? null;
    if (!is_array($lines) || !$lines) throw new ApiError('Rechnung ohne Positionen');
    $clean = []; $total = 0.0; $carryIds = [];
    foreach (array_values($lines) as $i => $l) {
        $kind = in_array($l['kind'] ?? '', ['main', 'carry', 'extra'], true) ? $l['kind'] : 'extra';
        $desc = vText($l['desc'] ?? '', 255);
        if ($desc === '') throw new ApiError('Position ' . ($i + 1) . ': Beschreibung fehlt');
        $amount = vMoneyOrNull($l['amount'] ?? null) ?? throw new ApiError('Position ' . ($i + 1) . ': Betrag fehlt');
        $carry = null;
        if ($kind === 'carry') {
            $carry = statementRow(vId($l['stmtId'] ?? 0))['id'];
            if ((int)$carry === (int)$s['id']) throw new ApiError('Carryover verweist auf dieselbe Abrechnung');
            $carryIds[] = (int)$carry;
        }
        $clean[] = [$i + 1, $kind, $carry, $desc, $amount];
        $total += (float)$amount;
    }
    $vals = [$no, $date, vText($in['recipient'] ?? '', 160), vText($in['address'] ?? '', 1000) ?: null, vText($in['greeting'] ?? '', 160),
             vText($in['intro'] ?? '', 2000) ?: null, number_format($total, 2, '.', ''), $s['currency']];
    $ex = $pdo->prepare("SELECT id FROM invoices WHERE statement_id=?");
    $ex->execute([$s['id']]);
    if ($invId = $ex->fetchColumn()) {
        $pdo->prepare("UPDATE invoices SET invoice_no=?, invoice_date=?, recipient_name=?, recipient_address=?, greeting=?, intro=?, total=?, currency=? WHERE id=?")
            ->execute([...$vals, $invId]);
        $pdo->prepare("DELETE FROM invoice_lines WHERE invoice_id=?")->execute([$invId]);
    } else {
        $pdo->prepare("INSERT INTO invoices (invoice_no, invoice_date, recipient_name, recipient_address, greeting, intro, total, currency, statement_id)
                       VALUES (?,?,?,?,?,?,?,?,?)")->execute([...$vals, $s['id']]);
        $invId = (int)$pdo->lastInsertId();
    }
    $ins = $pdo->prepare("INSERT INTO invoice_lines (invoice_id, pos, kind, carry_statement_id, description, amount) VALUES (?,?,?,?,?,?)");
    foreach ($clean as $c) $ins->execute([$invId, ...$c]);
    // carryovers on this invoice point to it; those taken off again are released (assignments made by hand stay)
    $pdo->prepare("UPDATE statements SET carried_into=NULL WHERE carried_into=? AND link_only=0")->execute([$s['id']]);
    if ($carryIds) {
        $ph = implode(',', array_fill(0, count($carryIds), '?'));
        $pdo->prepare("UPDATE statements SET carried_into=?, link_only=0 WHERE id IN ($ph)")->execute([$s['id'], ...$carryIds]);
    }
    if (array_key_exists('address', $in))
        $pdo->prepare("UPDATE labels SET address=? WHERE id=?")->execute([vText($in['address'], 1000), $s['label_id']]);
    $pdfId = null;
    if (!empty($in['pdf'])) $pdfId = pdfStore((string)$in['pdf'], $no, (int)$s['id']);
    $pdo->commit();
    return ['invoice_id' => (int)$invId, 'pdf_id' => $pdfId, 'total' => round($total, 2)];
}
/* append a generated invoice PDF to the archive (never overwritten) */
function pdfStore(string $b64, string $no, ?int $statementId): int {
    $bin = base64_decode($b64, true);
    if ($bin === false || !str_starts_with($bin, '%PDF')) throw new ApiError('PDF ungültig');
    if (strlen($bin) > MAX_PDF_BYTES) throw new ApiError('PDF zu groß');
    db()->prepare("INSERT INTO invoice_pdfs (invoice_no, statement_id, filename, bytes, sha256, pdf, created_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$no, $statementId, 'Invoice_' . preg_replace('/[^\w\-]/', '_', $no) . '.pdf', strlen($bin), hash('sha256', $bin), $bin, currentUserId()]);
    return (int)db()->lastInsertId();
}
/* send an archived PDF (by archive id, or the newest one of a statement) */
function pdfSend(array $q): never {
    if (!empty($q['id'])) {
        $st = db()->prepare("SELECT filename, pdf, sha256 FROM invoice_pdfs WHERE id=?");
        $st->execute([vId($q['id'])]);
    } else {
        $st = db()->prepare("SELECT filename, pdf, sha256 FROM invoice_pdfs WHERE statement_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([vId($q['statement'] ?? 0)]);
    }
    $p = $st->fetch();
    if (!$p) throw new ApiError('Kein PDF archiviert', 404);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (!empty($q['download']) ? 'attachment' : 'inline') . '; filename="' . $p['filename'] . '"');
    header('Content-Length: ' . strlen($p['pdf']));
    header('X-Content-SHA256: ' . $p['sha256']);
    http_response_code(200);
    echo $p['pdf'];
    exit;
}

/* ---------------- one-time takeover from the browser app ---------------- */
function invoicesImport(array $in): array {
    $rows = $in['statements'] ?? null;
    $addrs = $in['labelAddresses'] ?? [];
    if (!is_array($rows) || !$rows) throw new ApiError('Keine Abrechnungen übergeben');
    if (count($rows) > 5000) throw new ApiError('Zu viele Abrechnungen (max. 5000)');
    $pdo = db();
    $pdo->beginTransaction();
    if ((int)$pdo->query("SELECT COUNT(*) FROM statements FOR UPDATE")->fetchColumn() > 0)
        throw new ApiError('Auf dem Server sind bereits Abrechnungen – Übernahme nur in eine leere Liste', 409);
    $idMap = []; $nInv = 0;
    $ins = $pdo->prepare("INSERT INTO statements (label_id, statement_no, period_text, period_year, period_half, period_sort, period_start, period_end,
                          payable, carryover_stated, currency, status, source, import_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $step = function (int $i, array $s, callable $fn) use ($pdo) {
        try { return $fn(); }
        catch (ApiError | PDOException $e) {
            $pdo->rollBack();
            $msg = $e instanceof PDOException ? dbErrorMessage($e) : $e->getMessage();
            throw new ApiError('Abrechnung ' . ($i + 1) . ' (' . vText($s['label'] ?? '?', 60) . ' ' . vText($s['stmtNo'] ?? '', 40) . '): ' . $msg . ' – nichts übernommen');
        }
    };
    foreach (array_values($rows) as $i => $s) {
        if (!is_array($s)) continue;
        $step($i, $s, function () use ($s, $ins, &$idMap) {
            $year = vYear($s['year'] ?? null);
            $half = vHalf($s['half'] ?? null);
            $manual = ($s['source'] ?? '') === 'manual';
            $periodText = vText($s['periodText'] ?? '', 40) ?: trim(($half ?? '') . ' ' . $year);
            $sort = filter_var($s['sort'] ?? null, FILTER_VALIDATE_INT) ?: periodSort($year, $half);
            $status = in_array($s['status'] ?? 'open', STATUSES, true) ? $s['status'] ?? 'open' : 'open';
            $key = $manual ? null : (vText($s['id'] ?? '', 190) ?: null);
            $ins->execute([labelId((string)($s['label'] ?? '')), vText($s['stmtNo'] ?? '', 60), $periodText, $year, $half, $sort,
                           vText($s['periodStart'] ?? '', 20) ?: null, vText($s['periodEnd'] ?? '', 20) ?: null,
                           vMoneyOrNull($s['payable'] ?? null), vMoneyOrNull($s['carryover'] ?? 0) ?? '0.00', vCurrency($s['currency'] ?? '€'),
                           $status, $manual ? 'manual' : 'pdf', $key]);
            $idMap[(string)($s['id'] ?? '')] = (int)db()->lastInsertId();
        });
    }
    $link = $pdo->prepare("UPDATE statements SET carried_into=?, link_only=? WHERE id=?");
    foreach (array_values($rows) as $i => $s) {
        if (!is_array($s) || empty($s['carriedInto']) || !isset($idMap[(string)$s['carriedInto']])) continue;
        $link->execute([$idMap[(string)$s['carriedInto']], !empty($s['linkedOnly']) ? 1 : 0, $idMap[(string)$s['id']]]);
    }
    $insInv = $pdo->prepare("INSERT INTO invoices (statement_id, invoice_no, invoice_date, recipient_name, recipient_address, greeting, intro, total, currency)
                             VALUES (?,?,?,?,?,?,?,?,?)");
    $insLine = $pdo->prepare("INSERT INTO invoice_lines (invoice_id, pos, kind, carry_statement_id, description, amount) VALUES (?,?,?,?,?,?)");
    foreach (array_values($rows) as $i => $s) {
        if (!is_array($s) || empty($s['invoice']) || !is_array($s['invoice'])) continue;
        $step($i, $s, function () use ($s, $idMap, $insInv, $insLine, $addrs, &$nInv) {
            $v = $s['invoice'];
            $sid = $idMap[(string)$s['id']];
            $no = vText($v['no'] ?? '', 40);
            if ($no === '') throw new ApiError('Rechnungsnummer fehlt');
            $addr = is_array($addrs) ? ($addrs[labelKey((string)($s['label'] ?? ''))] ?? null) : null;
            $insInv->execute([$sid, $no, deDate((string)($v['date'] ?? '')), vText($v['recipient'] ?? ($s['label'] ?? ''), 160),
                              $addr !== null ? vText($addr, 1000) : null, vText($v['greeting'] ?? '', 160), vText($v['intro'] ?? '', 2000) ?: null,
                              vMoneyOrNull($v['total'] ?? 0) ?? '0.00', vCurrency($s['currency'] ?? '€')]);
            $invId = (int)db()->lastInsertId();
            foreach (array_values(is_array($v['lines'] ?? null) ? $v['lines'] : []) as $p => $l) {
                $kind = in_array($l['kind'] ?? '', ['main', 'carry', 'extra'], true) ? $l['kind'] : 'extra';
                $carry = $kind === 'carry' ? ($idMap[(string)($l['stmtId'] ?? '')] ?? null) : null;
                if ($kind === 'carry' && $carry === null) $kind = 'extra';           // carryover no longer in the list
                $insLine->execute([$invId, $p + 1, $kind, $carry, vText($l['desc'] ?? '', 255) ?: '–', vMoneyOrNull($l['amount'] ?? 0) ?? '0.00']);
            }
            $nInv++;
        });
    }
    $nAddr = 0;
    if (is_array($addrs)) foreach ($addrs as $key => $address) {
        $k = labelKey((string)$key);
        if ($k === '' || !is_string($address) || trim($address) === '') continue;
        $pdo->prepare("INSERT INTO labels (name, name_key, address) VALUES (?,?,?) ON DUPLICATE KEY UPDATE address=VALUES(address)")
            ->execute([(string)$key, $k, vText($address, 1000)]);
        $nAddr++;
    }
    $pdo->commit();
    return ['statements' => count($idMap), 'invoices' => $nInv, 'addresses' => $nAddr];
}
