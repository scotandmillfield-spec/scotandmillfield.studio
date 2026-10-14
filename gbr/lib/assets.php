<?php
declare(strict_types=1);
/* Fixed assets (Anlagen) for the AfA schedule. The schedule itself is computed in the app from
   cost, useful life and activation date; the server stores and validates the master data. */
if (!defined('GBR_API')) { http_response_code(404); exit; }

const ASSET_COLS = "id, name, cost, useful_life, activation_date, afa_account, asset_account, created_at";

function assetRow(array $r): array {
    return ['id' => (int)$r['id'], 'name' => $r['name'], 'cost' => (float)$r['cost'], 'years' => (int)$r['useful_life'],
            'date' => $r['activation_date'], 'afa_account' => $r['afa_account'], 'asset_account' => $r['asset_account'],
            'created_at' => $r['created_at']];
}
function assetsList(): array {
    return array_map('assetRow', db()->query("SELECT " . ASSET_COLS . " FROM assets ORDER BY activation_date, id")->fetchAll());
}
/* validated column values in table order (name, cost, useful_life, activation_date, afa_account, asset_account) */
function assetValues(array $in): array {
    $name = vText($in['name'] ?? '', 160);
    if ($name === '') throw new ApiError('Bezeichnung der Anlage fehlt');
    $years = filter_var($in['years'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
    if ($years === false) throw new ApiError('Nutzungsdauer: 1–50 Jahre');
    $afa = vText($in['afa_account'] ?? '', 10); $asset = vText($in['asset_account'] ?? '', 10);
    if ($afa === '' || $asset === '') throw new ApiError('AfA-Konto und Anlagekonto angeben');
    if ($afa === $asset) throw new ApiError('AfA-Konto und Anlagekonto müssen verschieden sein');
    return [$name, vAmount($in['cost'] ?? null), $years, vDate($in['date'] ?? '', 'Aktivierungsdatum'), $afa, $asset];
}
function assetGet(int $id): array {
    $st = db()->prepare("SELECT " . ASSET_COLS . " FROM assets WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new ApiError('Anlage nicht gefunden', 404);
    return assetRow($r);
}
/* create (no id) or update (id) */
function assetSave(array $in): array {
    $v = assetValues($in);
    if (!empty($in['id'])) {
        $id = assetGet(vId($in['id']))['id'];
        db()->prepare("UPDATE assets SET name=?, cost=?, useful_life=?, activation_date=?, afa_account=?, asset_account=? WHERE id=?")
            ->execute([...$v, $id]);
        return assetGet($id);
    }
    db()->prepare("INSERT INTO assets (name, cost, useful_life, activation_date, afa_account, asset_account) VALUES (?,?,?,?,?,?)")
        ->execute($v);
    return assetGet((int)db()->lastInsertId());
}
function assetDelete(array $in): void {
    $st = db()->prepare("DELETE FROM assets WHERE id=?");       // AfA bookings keep their amounts; their link is cleared (FK SET NULL)
    $st->execute([vId($in['id'] ?? 0)]);
    if (!$st->rowCount()) throw new ApiError('Anlage nicht gefunden', 404);
}
/* one-time takeover from the browser app (or a backup): only into an empty asset list, all or nothing.
   Accepts the browser format {name,cost,years,date,afaKonto,assetKonto}. */
function assetsImport(array $in): array {
    $rows = $in['assets'] ?? null;
    if (!is_array($rows) || !$rows) throw new ApiError('Keine Anlagen übergeben');
    if (count($rows) > 1000) throw new ApiError('Zu viele Anlagen (max. 1000)');
    $pdo = db();
    $pdo->beginTransaction();
    if ((int)$pdo->query("SELECT COUNT(*) FROM assets FOR UPDATE")->fetchColumn() > 0)
        throw new ApiError('Auf dem Server sind bereits Anlagen – Übernahme nur in eine leere Anlagenliste', 409);
    $ins = $pdo->prepare("INSERT INTO assets (name, cost, useful_life, activation_date, afa_account, asset_account) VALUES (?,?,?,?,?,?)");
    foreach (array_values($rows) as $i => $a) {
        try {
            if (!is_array($a)) throw new ApiError('ungültiger Eintrag');
            $a += ['afa_account' => $a['afaKonto'] ?? '4830', 'asset_account' => $a['assetKonto'] ?? '0420'];
            $ins->execute(assetValues($a));
        } catch (ApiError | PDOException $e) {
            $pdo->rollBack();
            $msg = $e instanceof PDOException ? dbErrorMessage($e) : $e->getMessage();
            throw new ApiError('Anlage ' . ($i + 1) . (is_array($a) && isset($a['name']) ? ' („' . vText($a['name'], 60) . '“)' : '') . ': ' . $msg . ' – nichts übernommen');
        }
    }
    $pdo->commit();
    return ['assets' => count($rows)];
}
