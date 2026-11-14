<?php
/**
 * Monthly report (#103) — a print-ready page (browser → “Save as PDF”).
 *
 * Why HTML and not a PDF library: the project ships with no runtime dependency, and the
 * browser already knows how to print. The page is A4-first (`@media print`), opens the
 * print dialog by itself, and is built entirely server-side so the numbers are the ones
 * the dashboard shows: `monthlyStatsData()` is the single source of the month's figures,
 * the inventory comes from the same rows the pantry lists, and the spending ledger is
 * the file the “spesa” session writes. Nothing here recomputes a rule of its own.
 *
 * Language: `?lang=` (validated), else NOTIFY_LANGUAGE, else English. Every word comes
 * from translations/*.json through evershelfTr(), so a new locale needs no code.
 */
declare(strict_types=1);

/** Month label (“Novembre 2026”) in the requested language. */
function evershelfReportMonthLabel(string $lang, string $month): string
{
    $ts = strtotime($month . '-01');
    $names = [
        1 => 'january', 2 => 'february', 3 => 'march', 4 => 'april', 5 => 'may', 6 => 'june',
        7 => 'july', 8 => 'august', 9 => 'september', 10 => 'october', 11 => 'november', 12 => 'december',
    ];
    $key = $names[(int)date('n', $ts)] ?? 'january';
    $label = evershelfTr('months.' . $key, $lang) ?: ucfirst($key);
    return $label . ' ' . date('Y', $ts);
}

/** The place as the household types it (built-in label, else the slug cleaned up). */
function evershelfReportPlaceLabel(string $lang, string $location): string
{
    $slug = trim($location);
    if ($slug === '') {
        return '';
    }
    $builtin = evershelfTr('locations.' . $slug, $lang);
    if ($builtin !== '' && $builtin !== 'locations.' . $slug) {
        return $builtin;
    }
    if (function_exists('inventoryLabelFromSlug')) {
        return inventoryLabelFromSlug($slug);
    }
    return ucfirst(str_replace('_', ' ', $slug));
}

/** `h()` — the escape this file uses (PHP has no escapeHtml()). */
function evershelfReportEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A quantity without trailing zeros (“2”, “0.5”). */
function evershelfReportQty(float $qty): string
{
    return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
}

/**
 * The data of one month, read from the sources the screens already use.
 *
 * @return array<string,mixed>
 */
function evershelfMonthlyReportData(PDO $db, string $month, string $lang): array
{
    $monthStart = $month . '-01';
    $monthEnd   = date('Y-m-01', strtotime($monthStart . ' +1 month'));
    $stats      = monthlyStatsData($db, $month, 10);

    // Inventory snapshot (the same rows the pantry lists).
    $rows = $db->query(
        "SELECT p.name, p.brand, p.category, i.location, i.quantity, p.unit, i.expiry_date, i.opened_at
           FROM inventory i JOIN products p ON i.product_id = p.id
          WHERE i.quantity > 0
          ORDER BY i.location ASC, p.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $today     = date('Y-m-d');
    $soonLimit = date('Y-m-d', strtotime('+7 days'));
    $perPlace  = [];
    $expiring  = 0;
    $expired   = 0;
    $opened    = 0;
    foreach ($rows as $r) {
        $place = (string)($r['location'] ?? '');
        $perPlace[$place] = ($perPlace[$place] ?? 0) + 1;
        $exp = (string)($r['expiry_date'] ?? '');
        if ($exp !== '') {
            if ($exp < $today) {
                $expired++;
            } elseif ($exp <= $soonLimit) {
                $expiring++;
            }
        }
        if (!empty($r['opened_at'])) {
            $opened++;
        }
    }

    // The month's discards, with the reason the household picked.
    $wasteRows = $db->query(
        "SELECT p.name, t.quantity, p.unit, COALESCE(t.notes, '') AS notes, t.created_at
           FROM transactions t JOIN products p ON t.product_id = p.id
          WHERE t.type = 'waste' AND t.undone = 0
            AND t.created_at >= '{$monthStart}' AND t.created_at < '{$monthEnd}'
          ORDER BY t.created_at DESC, t.id DESC
          LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Recorded spending (the “spesa” ledger: one amount per trip).
    $spendTotal = 0.0;
    $spendTrips = 0;
    $currency   = '€';
    if (function_exists('_spendLoadHistory')) {
        foreach (_spendLoadHistory() as $entry) {
            if (date('Y-m', (int)($entry['ts'] ?? 0)) !== $month) {
                continue;
            }
            $spendTotal += (float)($entry['amount'] ?? 0);
            $spendTrips++;
            $cur = trim((string)($entry['currency'] ?? ''));
            if ($cur !== '') {
                $currency = $cur;
            }
        }
    }

    return [
        'month' => $month,
        'lang' => $lang,
        'stats' => $stats,
        'rows' => $rows,
        'places' => $perPlace,
        'today' => $today,
        'soon_limit' => $soonLimit,
        'expiring' => $expiring,
        'expired' => $expired,
        'opened' => $opened,
        'waste_rows' => $wasteRows,
        'spend_total' => round($spendTotal, 2),
        'spend_trips' => $spendTrips,
        'spend_currency' => $currency,
    ];
}

/**
 * Build the report page. `$data` comes from evershelfMonthlyReportData().
 */
function evershelfMonthlyReportHtml(array $data): string
{
    $lang = (string)$data['lang'];
    $month = (string)$data['month'];
    $t = static fn(string $key, array $args = []): string => evershelfTr($key, $lang, $args);
    $e = static fn(string $v): string => evershelfReportEscape($v);
    $stats = (array)$data['stats'];

    $rowsHtml = '';
    foreach ($data['rows'] as $r) {
        $brand = trim((string)($r['brand'] ?? ''));
        $exp = (string)($r['expiry_date'] ?? '');
        $rowsHtml .= '<tr>'
            . '<td>' . $e((string)$r['name']) . ($brand !== '' ? '<br><small>' . $e($brand) . '</small>' : '') . '</td>'
            . '<td>' . $e(evershelfReportPlaceLabel($lang, (string)($r['location'] ?? ''))) . '</td>'
            . '<td class="num">' . $e(evershelfReportQty((float)$r['quantity']) . ' ' . (string)($r['unit'] ?? 'pz')) . '</td>'
            . '<td>' . ($exp !== '' ? $e($exp) : '—') . '</td>'
            . '</tr>';
    }

    $wasteHtml = '';
    foreach ($data['waste_rows'] as $w) {
        $reason = function_exists('_wasteReasonKey')
            ? (_wasteReasonKey((string)$w['notes']) ?? 'unknown')
            : 'other';
        $reasonLabel = $t('waste.reason_' . $reason);
        if ($reasonLabel === 'waste.reason_' . $reason) {
            $reasonLabel = $t('waste.reason_skip');
        }
        $wasteHtml .= '<tr>'
            . '<td>' . $e((string)$w['name']) . '</td>'
            . '<td class="num">' . $e(evershelfReportQty((float)$w['quantity']) . ' ' . (string)($w['unit'] ?? 'pz')) . '</td>'
            . '<td>' . $e($reasonLabel) . '</td>'
            . '<td>' . $e(substr((string)$w['created_at'], 0, 10)) . '</td>'
            . '</tr>';
    }

    $places = '';
    foreach ($data['places'] as $slug => $count) {
        $places .= '<span class="chip">' . $e(evershelfReportPlaceLabel($lang, (string)$slug))
            . ' · ' . (int)$count . '</span>';
    }

    $topProds = '';
    foreach (($stats['top_products'] ?? []) as $p) {
        $topProds .= '<li>' . $e((string)($p['name'] ?? ''))
            . ' <span class="muted">× ' . (int)($p['count'] ?? 0) . '</span></li>';
    }
    $topCats = '';
    foreach (($stats['top_categories'] ?? []) as $c) {
        $topCats .= '<li>' . $e(ucfirst((string)($c['cat'] ?? '')))
            . ' <span class="muted">' . (int)($c['pct'] ?? 0) . '%</span></li>';
    }
    $noneList = '<li class="muted">' . $e($t('report.none')) . '</li>';
    if ($topProds === '') { $topProds = $noneList; }
    if ($topCats === '') { $topCats = $noneList; }
    if ($wasteHtml === '') {
        $wasteHtml = '<tr><td colspan="4" class="muted">' . $e($t('report.none')) . '</td></tr>';
    }

    $added        = (int)($stats['items_added'] ?? 0);
    $consumed     = (int)($stats['items_consumed'] ?? 0);
    $consumedPrev = (int)($stats['items_consumed_prev'] ?? 0);
    $wasted       = (int)($stats['items_wasted'] ?? 0);
    $wastedValue  = (float)($stats['wasted_value_eur'] ?? 0);
    $money        = static fn(float $v, string $cur): string => number_format($v, 2, '.', '') . ' ' . $cur;

    $monthLabel = evershelfReportMonthLabel($lang, $month);
    $title      = $t('report.title');
    $langAttr   = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en';
    $placeCount = count($data['rows']);
    $spendTrips = (int)$data['spend_trips'];
    $spendTotal = $money((float)$data['spend_total'], (string)$data['spend_currency']);

    ob_start();
    ?>

<!DOCTYPE html>
<html lang="<?= $e($langAttr) ?>">
<head>
<meta charset="UTF-8">
<title>evershelf-report-<?= $e($month) ?></title>
<style>
  :root{--ink:#1a1a1a;--muted:#6b7280;--line:#e5e7eb;--brand:#2d5016;--wash:#f8fafc}
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;font-size:12px;margin:24px;color:var(--ink)}
  h1{font-size:20px;margin:0 0 4px}
  h2{font-size:14px;margin:22px 0 8px;padding-bottom:4px;border-bottom:2px solid var(--brand)}
  .subtitle{color:var(--muted);font-size:11px;margin-bottom:14px}
  .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:8px 0 4px}
  .kpi{border:1px solid var(--line);border-radius:8px;padding:8px 10px;background:var(--wash)}
  .kpi b{display:block;font-size:18px;line-height:1.2}
  .kpi span{color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.03em}
  .cols{display:grid;grid-template-columns:1fr 1fr;gap:18px}
  ul{margin:4px 0 0 16px;padding:0}
  li{margin:2px 0}
  table{width:100%;border-collapse:collapse;margin-top:6px}
  th{background:var(--brand);color:#fff;padding:6px 8px;text-align:left;font-size:11px}
  td{padding:5px 8px;border-bottom:1px solid var(--line);vertical-align:top}
  tr:nth-child(even) td{background:var(--wash)}
  small,.muted{color:var(--muted)}
  .num{text-align:right;white-space:nowrap}
  .chip{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:2px 8px;margin:0 6px 6px 0;font-size:11px}
  footer{margin-top:22px;color:var(--muted);font-size:10px;border-top:1px solid var(--line);padding-top:8px}
  @media print{
    body{margin:12px}
    button{display:none}
    @page{margin:15mm}
    h2{break-after:avoid}
    tr{break-inside:avoid}
  }
</style>
</head>
<body>
<button onclick="window.print()" style="margin-bottom:14px;padding:8px 16px;background:var(--brand);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px">🖨️ <?= $e($t('report.print')) ?></button>
<h1>🏠 <?= $e($title) ?> — <?= $e($monthLabel) ?></h1>
<div class="subtitle"><?= $e($t('report.period', ['month' => $monthLabel])) ?></div>

<div class="grid">
  <div class="kpi"><b><?= $added ?></b><span><?= $e($t('report.added')) ?></span></div>
  <div class="kpi"><b><?= $consumed ?></b><span><?= $e($t('report.consumed')) ?></span></div>
  <div class="kpi"><b><?= $wasted ?></b><span><?= $e($t('report.wasted_count')) ?></span></div>
  <div class="kpi"><b><?= $e(evershelfReportQty($wastedValue)) ?></b><span><?= $e($t('report.wasted_value')) ?></span></div>
</div>

<h2><?= $e($t('report.snapshot')) ?> · <?= $e($t('report.stock_total')) ?>: <?= $placeCount ?></h2>
<div><?= $places ?></div>
<div class="subtitle"><?= $e($t('report.snapshot_hint', ['date' => $data['today'], 'soon' => $data['soon_limit']])) ?></div>
<div class="grid">
  <div class="kpi"><b><?= (int)$data['expiring'] ?></b><span><?= $e($t('report.expiring')) ?></span></div>
  <div class="kpi"><b><?= (int)$data['expired'] ?></b><span><?= $e($t('report.expired')) ?></span></div>
  <div class="kpi"><b><?= (int)$data['opened'] ?></b><span><?= $e($t('report.opened')) ?></span></div>
  <div class="kpi"><b><?= $consumedPrev ?></b><span><?= $e($t('report.prev_month')) ?></span></div>
</div>
<table>
<thead><tr>
  <th><?= $e($t('report.col_name')) ?></th><th><?= $e($t('report.col_place')) ?></th>
  <th class="num"><?= $e($t('report.col_qty')) ?></th><th><?= $e($t('report.col_expiry')) ?></th>
</tr></thead>
<tbody><?= $rowsHtml ?></tbody>
</table>

<h2><?= $e($t('report.consumption')) ?></h2>
<div class="cols">
  <div><b><?= $e($t('report.top_products')) ?></b><ul><?= $topProds ?></ul></div>
  <div><b><?= $e($t('report.top_categories')) ?></b><ul><?= $topCats ?></ul></div>
</div>

<h2><?= $e($t('report.waste')) ?> · <?= $e($t('report.wasted_value')) ?>: <?= $e(evershelfReportQty($wastedValue)) ?></h2>
<table>
<thead><tr>
  <th><?= $e($t('report.col_name')) ?></th><th class="num"><?= $e($t('report.col_qty')) ?></th>
  <th><?= $e($t('report.col_reason')) ?></th><th><?= $e($t('report.col_date')) ?></th>
</tr></thead>
<tbody><?= $wasteHtml ?></tbody>
</table>

<h2><?= $e($t('report.spend')) ?></h2>
<p>
  <?= $e($t('report.spend_trips')) ?>: <b><?= $spendTrips ?></b> ·
  <?= $e($t('report.spend_total')) ?>: <b><?= $e($spendTotal) ?></b>
</p>

<footer><?= $e($t('report.generated', ['date' => date('Y-m-d H:i')])) ?> · EverShelf</footer>
<script>window.onload=function(){window.print();};</script>
</body>
</html>
<?php
    return (string)ob_get_clean();
}

/**
 * action=export_monthly_report — GET, opener = the Settings → Dati button.
 * `?month=YYYY-MM` (default: this month), `?lang=xx` (default: NOTIFY_LANGUAGE).
 */
function exportMonthlyReport(PDO $db): void
{
    $month = (string)($_GET['month'] ?? '');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        $month = date('Y-m');
    }
    $lang = strtolower(trim((string)($_GET['lang'] ?? '')));
    if (!preg_match('/^[a-z]{2}$/', $lang)) {
        $lang = function_exists('evershelfNotifyLanguage') ? evershelfNotifyLanguage() : 'en';
    }
    $html = evershelfMonthlyReportHtml(evershelfMonthlyReportData($db, $month, $lang));
    EverLog::info('Monthly report generated', [
        'event' => 'api_export_monthly_report',
        'month' => $month,
        'lang'  => $lang,
    ]);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;
}

