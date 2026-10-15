<?php
declare(strict_types=1);
/* Document archive: bank statements and receipts (Belege) as PDF or image, linked to bookings.
   A file is stored once (sha256); it is never changed, and only deleted while no booking uses it.
   Links to a locked (festgeschrieben) booking can be added but not removed. */
if (!defined('GBR_API')) { http_response_code(404); exit; }

const DOC_MAX_BYTES = 12 * 1024 * 1024;     // below MEDIUMBLOB (16 MB) and typical upload limits

/* the file type is taken from the content, never from the name: only PDFs and photos are accepted */
function docMime(string $bin): string {
    if (str_starts_with($bin, '%PDF')) return 'application/pdf';
    if (str_starts_with($bin, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($bin, "\x89PNG\r\n\x1A\n")) return 'image/png';
    if (substr($bin, 0, 4) === 'RIFF' && substr($bin, 8, 4) === 'WEBP') return 'image/webp';
    if (substr($bin, 4, 4) === 'ftyp' && in_array(substr($bin, 8, 4), ['heic', 'heix', 'mif1', 'msf1'], true)) return 'image/heic';
    throw new ApiError('Nur PDF, JPG, PNG, WebP oder HEIC möglich');
}
/* store a file (or find the identical one already stored); returns its id */
function docStore(string $bin, string $name, string $kind): int {
    if ($bin === '') throw new ApiError('Datei ist leer');
    if (strlen($bin) > DOC_MAX_BYTES) throw new ApiError('Datei zu groß (max. ' . (DOC_MAX_BYTES >> 20) . ' MB)');
    $mime = docMime($bin);
    $sha = hash('sha256', $bin);
    $st = db()->prepare("SELECT id FROM documents WHERE sha256=?");
    $st->execute([$sha]);
    if ($id = $st->fetchColumn()) return (int)$id;
    $name = vText(preg_replace('/[^\w.\- ()äöüÄÖÜß]+/u', '_', $name), 160) ?: 'beleg';
    db()->prepare("INSERT INTO documents (kind, filename, mime, bytes, sha256, content, created_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$kind === 'bank' ? 'bank' : 'beleg', $name, $mime, strlen($bin), $sha, $bin, currentUserId()]);
    return (int)db()->lastInsertId();
}
/* the uploaded file of field $field (multipart) as [content, name] */
function uploadedFile(string $field): array {
    $f = $_FILES[$field] ?? null;
    if (!$f || is_array($f['error'])) throw new ApiError('Keine Datei übermittelt');
    if ($f['error'] !== UPLOAD_ERR_OK) throw new ApiError(match ($f['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Datei zu groß für den Server (upload_max_filesize)',
        UPLOAD_ERR_PARTIAL => 'Upload unvollständig – bitte erneut versuchen',
        UPLOAD_ERR_NO_FILE => 'Keine Datei übermittelt',
        default => 'Upload fehlgeschlagen (Fehler ' . $f['error'] . ')',
    });
    return [(string)file_get_contents($f['tmp_name']), (string)$f['name']];
}
/* link a document to a booking (idempotent) and note it in the booking's change log */
function docLink(int $bookingId, int $docId): bool {
    $st = db()->prepare("INSERT IGNORE INTO booking_documents (booking_id, document_id, created_by) VALUES (?,?,?)");
    $st->execute([$bookingId, $docId, currentUserId()]);
    if (!$st->rowCount()) return false;
    $d = docInfo($docId);
    bookingLog($bookingId, 'attach', ['document' => $d]);
    return true;
}
function docInfo(int $id): array {
    $st = db()->prepare("SELECT id, kind, filename, mime, bytes, sha256, created_at FROM documents WHERE id=?");
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) throw new ApiError('Dokument nicht gefunden', 404);
    return ['id' => (int)$d['id'], 'kind' => $d['kind'], 'name' => $d['filename'], 'mime' => $d['mime'], 'bytes' => (int)$d['bytes'],
            'sha256' => $d['sha256'], 'created_at' => $d['created_at']];
}
/* documents per booking, for the journal list: [booking_id => [{id,kind,name,mime}]] */
function docsByBooking(?string $year = null): array {
    $sql = "SELECT bd.booking_id, d.id, d.kind, d.filename, d.mime FROM booking_documents bd JOIN documents d ON d.id=bd.document_id";
    $args = [];
    if ($year !== null) { $sql .= " JOIN bookings b ON b.id=bd.booking_id WHERE b.booking_date BETWEEN ? AND ?"; $args = ["$year-01-01", "$year-12-31"]; }
    $st = db()->prepare($sql . " ORDER BY d.kind, d.id");
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r)
        $out[(int)$r['booking_id']][] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'name' => $r['filename'], 'mime' => $r['mime']];
    return $out;
}

/* ---- API ---- */
/* multipart: file + payload {booking_id?, kind} → stored (or found) and linked */
function docUpload(array $in): array {
    [$bin, $name] = uploadedFile('file');
    $pdo = db();
    $pdo->beginTransaction();
    $id = docStore($bin, $name, (string)($in['kind'] ?? 'beleg'));
    $linked = false;
    if (!empty($in['booking_id'])) { bookingGet(vId($in['booking_id'])); $linked = docLink(vId($in['booking_id']), $id); }
    $pdo->commit();
    return ['document' => docInfo($id), 'linked' => $linked];
}
function docLinkApi(array $in): array {
    $pdo = db();
    $pdo->beginTransaction();
    $b = bookingGet(vId($in['booking_id'] ?? 0), true);
    $d = docInfo(vId($in['document_id'] ?? 0));
    $linked = docLink($b['id'], $d['id']);
    $pdo->commit();
    return ['linked' => $linked];
}
function docUnlink(array $in): void {
    $pdo = db();
    $pdo->beginTransaction();
    $b = bookingGet(vId($in['booking_id'] ?? 0), true);
    if ($b['locked']) throw new ApiError('Buchung #' . $b['no'] . ' ist festgeschrieben – Belege können ergänzt, aber nicht entfernt werden', 409);
    $d = docInfo(vId($in['document_id'] ?? 0));
    $st = $pdo->prepare("DELETE FROM booking_documents WHERE booking_id=? AND document_id=?");
    $st->execute([$b['id'], $d['id']]);
    if (!$st->rowCount()) throw new ApiError('Beleg ist dieser Buchung nicht zugeordnet', 404);
    bookingLog($b['id'], 'detach', ['document' => $d]);
    $pdo->commit();
}
/* archive list (newest first) with the bookings each document belongs to */
function docList(array $q): array {
    $kind = in_array($q['kind'] ?? '', ['bank', 'beleg'], true) ? $q['kind'] : null;
    $st = db()->prepare("SELECT d.id, d.kind, d.filename, d.mime, d.bytes, d.created_at,
                           GROUP_CONCAT(b.no ORDER BY b.no) AS nos
                         FROM documents d LEFT JOIN booking_documents bd ON bd.document_id=d.id LEFT JOIN bookings b ON b.id=bd.booking_id"
                       . ($kind ? " WHERE d.kind=?" : "") . " GROUP BY d.id ORDER BY d.id DESC LIMIT 500");
    $st->execute($kind ? [$kind] : []);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'kind' => $r['kind'], 'name' => $r['filename'], 'mime' => $r['mime'], 'bytes' => (int)$r['bytes'],
        'created_at' => $r['created_at'], 'bookings' => $r['nos'] === null ? [] : array_map('intval', explode(',', $r['nos']))], $st->fetchAll());
}
function docDelete(array $in): void {
    $id = vId($in['id'] ?? 0);
    $used = db()->prepare("SELECT COUNT(*) FROM booking_documents WHERE document_id=?");
    $used->execute([$id]);
    if ((int)$used->fetchColumn() > 0) throw new ApiError('Dokument ist noch mit Buchungen verbunden – erst dort lösen', 409);
    $st = db()->prepare("DELETE FROM documents WHERE id=?");
    $st->execute([$id]);
    if (!$st->rowCount()) throw new ApiError('Dokument nicht gefunden', 404);
}
function docSend(array $q): never {
    $st = db()->prepare("SELECT filename, mime, content, sha256 FROM documents WHERE id=?");
    $st->execute([vId($q['id'] ?? 0)]);
    $d = $st->fetch();
    if (!$d) throw new ApiError('Dokument nicht gefunden', 404);
    header('Content-Type: ' . $d['mime']);
    header('Content-Disposition: ' . (!empty($q['download']) ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $d['filename']) . '"');
    header('Content-Length: ' . strlen($d['content']));
    header('X-Content-SHA256: ' . $d['sha256']);
    http_response_code(200);
    echo $d['content'];
    exit;
}
