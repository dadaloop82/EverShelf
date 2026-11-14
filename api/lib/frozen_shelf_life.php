<?php
/**
 * Freezer lifecycle (#286) — how long a category keeps in the freezer.
 *
 * The numbers live in `data/frozen_shelf_life.json` (data, not code: a household can
 * edit the file, and a new category needs no release). This file is the only reader:
 * `inventory_freeze` stores today + these days as the best-before date, and the
 * original date is what comes back (limited by the 48 h thaw window) on `inventory_thaw`.
 */
declare(strict_types=1);

/** How many days a thawed item has before it must be eaten. */
const EVERSHELF_THAW_DAYS = 2;

/** @return array{default_days:int,by_category:array<string,int>} */
function frozenShelfLifeTable(): array
{
    static $table = null;
    if (is_array($table)) {
        return $table;
    }
    $path = dirname(__DIR__, 2) . '/data/frozen_shelf_life.json';
    $default = 90;
    $byCategory = [];
    if (is_file($path)) {
        $raw = json_decode((string)file_get_contents($path), true);
        if (is_array($raw)) {
            if (isset($raw['default_days']) && (int)$raw['default_days'] > 0) {
                $default = (int)$raw['default_days'];
            }
            foreach ((array)($raw['by_category'] ?? []) as $cat => $days) {
                $days = (int)$days;
                if ($days > 0) {
                    $byCategory[mb_strtolower(trim((string)$cat))] = $days;
                }
            }
        }
    }
    $table = ['default_days' => $default, 'by_category' => $byCategory];
    return $table;
}

/** Freezer days for one category slug (unknown category → the table's default). */
function frozenShelfLifeDays(string $category = ''): int
{
    $table = frozenShelfLifeTable();
    $cat = mb_strtolower(trim($category));
    if ($cat !== '' && isset($table['by_category'][$cat])) {
        return (int)$table['by_category'][$cat];
    }
    return (int)$table['default_days'];
}
