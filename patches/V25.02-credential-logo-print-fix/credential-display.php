<?php
/** TRAVSCOPE V25.02: expandable credential library; preserve V25.01 keys. */
declare(strict_types=1);

if (!defined('TS_CREDENTIAL_MAX_SLOTS')) define('TS_CREDENTIAL_MAX_SLOTS', 30);

if (!function_exists('tsCredSetting')) {
function tsCredSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        if (function_exists('getSetting')) return trim((string)getSetting($pdo, $key, $default));
        $s = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false ? $default : trim((string)$v);
    } catch (Throwable $e) { return $default; }
}
function tsCredSafeLogo(string $logo): string {
    $logo = trim($logo);
    if ($logo === '' || preg_match('/[\x00-\x1f<>"\']/', $logo)) return '';
    if (preg_match('#^https://#i', $logo)) {
        $parts = parse_url($logo);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user'])) return '';
        return preg_match('/\.(?:png|jpe?g)(?:[?#]|$)/i', $logo) ? $logo : '';
    }
    $path = ltrim(str_replace('\\', '/', $logo), '/');
    if ($path === '' || str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '#')) return '';
    if (!preg_match('#^(?:uploads/settings/branding/|assets/)[a-zA-Z0-9_./-]+\.(?:png|jpe?g)$#i', $path)) return '';
    return $path;
}
function tsCredentialItems(PDO $pdo): array {
    $items = [];
    // V25.02: one settings lookup for the shared footer/PDF renderer instead
    // of 90 queries when the library contains 30 configured slots.
    $all = null;
    try {
        $st = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'quotation_credential_%'");
        if ($st) {
            $all = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $all[(string)$row['setting_key']] = (string)$row['setting_value'];
            }
        }
    } catch (Throwable $e) { $all = null; }
    $setting = static function (string $key, string $default = '') use ($all, $pdo): string {
        return is_array($all) ? trim((string)($all[$key] ?? $default)) : tsCredSetting($pdo, $key, $default);
    };
    for ($i=1; $i<=TS_CREDENTIAL_MAX_SLOTS; $i++) {
        // Explicit approval is necessary: uploading or typing a logo must never assert an affiliation.
        if ($setting('quotation_credential_verified_'.$i, '0') !== '1') continue;
        $label = $setting('quotation_credential_label_'.$i);
        $logo = tsCredSafeLogo($setting('quotation_credential_logo_'.$i));
        if ($label === '' || $logo === '') continue;
        $items[] = ['label' => (function_exists('mb_substr') ? mb_substr($label, 0, 120) : substr($label, 0, 120)), 'logo' => $logo, 'slot' => $i];
    }
    return $items;
}
function tsCredentialImageUrl(string $logo): string {
    if (preg_match('#^https://#i', $logo)) return $logo;
    return (defined('BASE_URL') ? rtrim((string)BASE_URL, '/') . '/' : '/') . ltrim($logo, '/');
}
function tsCredentialsHtml(PDO $pdo, string $where = 'document'): string {
    $items = tsCredentialItems($pdo);
    if (!$items) return '';
    $heading = tsCredSetting($pdo, 'quotation_credentials_heading', 'Registrations & Credentials');
    // Keep the layout with the markup: a missing external stylesheet must not
    // let the public site's full-width image rule enlarge trust marks in PDFs.
    $html = <<<'CSS'
<style>
.ts-credentials{box-sizing:border-box;clear:both;max-width:1150px;margin:20px auto;padding:16px 18px;border:1px solid #dce6f2;border-radius:12px;background:#fff;color:#173253;font-family:inherit;break-inside:avoid;page-break-inside:avoid}
.ts-credentials *{box-sizing:border-box}
.ts-credentials-heading{font-size:12px;font-weight:800;letter-spacing:.055em;text-transform:uppercase;margin-bottom:12px}
.ts-credentials-items{display:flex;flex-wrap:wrap;justify-content:center;gap:14px 26px;align-items:center}
.ts-credentials-item{display:flex;align-items:center;gap:8px;max-width:230px;min-width:115px;flex:0 1 auto}
.ts-credentials-item img{width:auto;max-width:75px;height:42px;object-fit:contain;flex-shrink:0}
.ts-credentials-item span{font-size:11px;font-weight:650;line-height:1.4;overflow-wrap:anywhere}
.ts-credentials-note{margin:10px 0 0!important;font-size:10px!important;line-height:1.4!important;color:#72819a!important;text-align:center}
.ts-credentials--website{max-width:none;margin:0;padding:26px max(18px,calc((100vw - 1200px)/2));border:0;border-top:1px solid #dce6f2;border-radius:0;background:#f5f9ff}
.ts-credentials--document{margin:14px 0 4px;padding:10px 12px;border-radius:8px}
.ts-credentials--document .ts-credentials-heading{font-size:10px;margin-bottom:8px}
.ts-credentials--document .ts-credentials-items{gap:8px 16px}
.ts-credentials--document .ts-credentials-item{min-width:100px;max-width:175px;gap:7px}
.ts-credentials--document .ts-credentials-item img{max-width:55px;height:30px}
.ts-credentials--document .ts-credentials-item span{font-size:9px}
.ts-credentials--document .ts-credentials-note{font-size:8px!important;margin:6px 0 0!important}
@media(max-width:540px){.ts-credentials-items{justify-content:flex-start}.ts-credentials-item{width:calc(50% - 15px);max-width:none;min-width:0}.ts-credentials--website{padding:22px 16px}.ts-credentials-item img{max-width:55px;height:35px}}
@media print{@page{size:A4}.ts-credentials{box-shadow:none!important;border-color:#dce6f2!important;background:#fff!important;margin:5mm 0 2mm!important;padding:2.5mm!important;break-inside:avoid!important;page-break-inside:avoid!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.ts-credentials--document .ts-credentials-item img{max-width:14mm;height:8mm}.ts-credentials--document .ts-credentials-items{gap:2mm 4mm}.ts-credentials--document .ts-credentials-note{font-size:7px!important}}
@media print{.ts-credentials .ts-credentials-items{flex-wrap:wrap!important;gap:2mm 4mm}.ts-credentials .ts-credentials-item{flex:0 1 28mm!important;min-width:19mm!important;max-width:40mm!important;gap:1.5mm}.ts-credentials .ts-credentials-item img{max-width:12mm;height:7mm}.ts-credentials .ts-credentials-item span{font-size:8px;line-height:1.3}}
</style>
CSS;
    $html .= '<section class="ts-credentials ts-credentials--'.($where === 'website'?'website':'document').'" aria-label="'.htmlspecialchars($heading,ENT_QUOTES,'UTF-8').'">';
    $html .= '<div class="ts-credentials-heading">'.htmlspecialchars($heading,ENT_QUOTES,'UTF-8').'</div><div class="ts-credentials-items">';
    foreach ($items as $it) {
        $label = htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars(tsCredentialImageUrl($it['logo']), ENT_QUOTES, 'UTF-8');
        $html .= '<div class="ts-credentials-item"><img loading="lazy" src="'.$url.'" alt="'.$label.' logo"><span>'.$label.'</span></div>';
    }
    $html .= '</div><p class="ts-credentials-note">Registration or association marks are displayed as supplied by TRAVSCOPE. No government endorsement is implied.</p></section>';
    return $html;
}
}
