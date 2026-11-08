#!/usr/bin/env php
<?php
/**
 * Re-anchor the best-before dates that were counted from the WRONG day.
 *
 * Two defects produced dates that are not "the date the household typed":
 *
 *  1. The one-time backfill that gives a date to rows stored without one counted the shelf life
 *     from **today** instead of from the day the row entered the pantry, so four rows of salt added
 *     on 2026-03-10 were dated 2027-11-07 (the day the pass ran, plus a year).
 *  2. The 9999 sentinel the estimators return for a non-perishable (salt, sugar, honey, vinegar,
 *     bicarbonate) was turned into a real date: today + 9999 days = **2054-03-13**.
 *
 * This pass finds exactly those rows and moves them where they belong:
 *   - a row whose expiry equals `updated_at + estimate` but NOT `added_at + estimate` (the wrong
 *     anchor) is re-anchored on `added_at`;
 *   - a row dated in the year 2050 or later is CLEARED (a pantry may say "no expiry").
 * A row whose date the household set (`expiry_user_set = 1`) is NEVER touched: those are the only
 * dates that came from a package.
 *
 * Usage:
 *   php scripts/fix-expiry-anchors.php              # dry run, prints what it would do
 *   php scripts/fix-expiry-anchors.php --apply      # writes (VACUUM INTO backup first)
 *   php scripts/fix-expiry-anchors.php --apply --no-backup
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/database.php';

$apply = false;
$backup = true;
$minShift = 3;   // days; a 1-day difference is a row bought the day before it was used
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply')            $apply = true;
    elseif ($arg === '--no-backup')    $backup = false;
    elseif (str_starts_with($arg, '--min-shift=')) $minShift = max(0, (int)substr($arg, 12));
    elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php scripts/fix-expiry-anchors.php [--apply] [--no-backup] [--min-shift=N]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
}

$db = getDB();
$rows = $db->query("
    SELECT i.id, i.location, i.added_at, i.updated_at, i.expiry_date, i.expiry_user_set, i.opened_at,
           p.name, p.category
      FROM inventory i
      JOIN products p ON p.id = i.product_id
     WHERE i.expiry_date IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

$reanchor = [];
$clear = [];
$skippedUser = 0;

foreach ($rows as $r) {
    if ((int)$r['expiry_user_set'] === 1) { $skippedUser++; continue; }
    $kind = storageKindOfLocationPHP((string)$r['location']);
    $days = estimateSealedExpiryDaysPHP((string)$r['name'], (string)$r['category'], $kind);

    // (2) a date in 2050+ is the 9999 sentinel that became a day
    if ((int)substr((string)$r['expiry_date'], 0, 4) >= 2050) {
        $clear[] = $r;
        continue;
    }
    // (1) same estimate as today, but hung off the wrong day
    $fromAdded = expiryDateFromDays($days, (string)$r['added_at']);
    $fromUpd   = expiryDateFromDays($days, (string)$r['updated_at']);
    if ($fromUpd !== null && $fromAdded !== null
        && $r['expiry_date'] === $fromUpd && $r['expiry_date'] !== $fromAdded) {
        // A date that is off by a day is a row bought the day before it was used (the estimate was
        // re-anchored on the use); correcting it changes nothing for the household and only makes
        // the report noisy. The damage this pass exists for is measured in weeks and months.
        $shift = abs((strtotime($fromAdded) - strtotime((string)$r['expiry_date'])) / 86400);
        if ($shift < $minShift) continue;
        $r['_shift'] = (int)round($shift);
        $r['_new'] = $fromAdded;
        $reanchor[] = $r;
    }
}

printf("Inventario: %d righe con scadenza, %d con data impostata a mano (intatte)\n",
    count($rows), $skippedUser);
printf("Da ri-ancorare al giorno d'acquisto: %d\n", count($reanchor));
printf("Da azzerare (anno >= 2050, sentinella): %d\n", count($clear));

foreach (array_slice($reanchor, 0, 20) as $r) {
    printf("  id %-5d %-30s %s -> %s\n", $r['id'], mb_substr((string)$r['name'], 0, 30),
        $r['expiry_date'], $r['_new']);
}
if (count($reanchor) > 20) printf("  … e altre %d\n", count($reanchor) - 20);
foreach ($clear as $r) {
    printf("  id %-5d %-30s %s -> (nessuna scadenza)\n", $r['id'], mb_substr((string)$r['name'], 0, 30), $r['expiry_date']);
}

if (!$apply) {
    fwrite(STDOUT, "\nDRY RUN: non ho scritto niente. Rilancia con --apply per applicare.\n");
    exit(0);
}

if ($backup) {
    $dir = __DIR__ . '/../data/backups';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/pre-expiry-fix-' . date('Ymd-His') . '.db';
    try {
        $db->exec('VACUUM INTO ' . $db->quote($file));
        fwrite(STDOUT, "Backup: {$file}\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "Backup non riuscito ({$e->getMessage()}) — non tocco niente.\n");
        exit(1);
    }
}

$upd = $db->prepare('UPDATE inventory SET expiry_date = ? WHERE id = ? AND COALESCE(expiry_user_set, 0) = 0');
$db->beginTransaction();
foreach ($reanchor as $r) $upd->execute([$r['_new'], (int)$r['id']]);
foreach ($clear as $r)    $upd->execute([null, (int)$r['id']]);
$db->commit();

printf("Fatto: %d ri-ancorate, %d azzerate.\n", count($reanchor), count($clear));
