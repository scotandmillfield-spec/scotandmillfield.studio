<?php
declare(strict_types=1);
/* App settings (table settings: key → JSON). Only known keys with known fields are accepted. */
if (!defined('GBR_API')) { http_response_code(404); exit; }

/* key => allowed fields (all strings) */
const SETTING_FIELDS = [
    'issuer' => ['name', 'addr', 'iban', 'bic', 'taxNo', 'place', 'signoff'],   // letterhead for invoices and the tax export
];

function settingsAll(): array {
    $out = array_fill_keys(array_keys(SETTING_FIELDS), null);
    foreach (db()->query("SELECT k, v FROM settings")->fetchAll() as $r)
        if (array_key_exists($r['k'], $out)) $out[$r['k']] = json_decode($r['v'], true);
    return $out;
}
function settingSave(array $in): array {
    $key = (string)($in['key'] ?? '');
    if (!isset(SETTING_FIELDS[$key])) throw new ApiError('Unbekannte Einstellung');
    $value = $in['value'] ?? null;
    if (!is_array($value)) throw new ApiError('Wert fehlt');
    $clean = [];
    foreach (SETTING_FIELDS[$key] as $f) $clean[$f] = vText(is_scalar($value[$f] ?? '') ? ($value[$f] ?? '') : '', 300);
    db()->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
        ->execute([$key, json_encode($clean, JSON_UNESCAPED_UNICODE)]);
    return $clean;
}
