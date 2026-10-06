<?php
/**
 * EverShelf — automatic favourites.
 *
 * Only the household's absolute top-N products by real consumption become
 * favourites on their own (default N = 3). A product must still clear
 * autoFavoriteMinUses() inside the window; reaching the threshold alone is no
 * longer enough — otherwise every staple the family touches would fill the
 * favourites rail.
 *
 * Two guards keep the automatic rule from fighting the user:
 *   * products.favorite_user_override = 1 as soon as the user unstars a favourite,
 *     so a manual decision always wins and the rule never re-adds it,
 *   * the rule only ever ADDS favourites — it never removes one (a product that
 *     drops out of the top N keeps its star).
 *
 * Config: AUTO_FAVORITE_MIN_USES (default 3, 0 = off), AUTO_FAVORITE_TOP_N (3),
 *         AUTO_FAVORITE_WINDOW_DAYS (90).
 */

/** How many consumptions are required before a product can even compete for a slot (0 disables). */
function autoFavoriteMinUses(): int {
    return max(0, (int)env('AUTO_FAVORITE_MIN_USES', '3'));
}

/** How many of the most-used products may be auto-starred (default 3). */
function autoFavoriteTopN(): int {
    return max(1, (int)env('AUTO_FAVORITE_TOP_N', '3'));
}

/** Rolling window in days — mirrors the window recentPopularProducts() reports. */
function autoFavoriteWindowDays(): int {
    return max(1, (int)env('AUTO_FAVORITE_WINDOW_DAYS', '90'));
}

/**
 * How many times a product was really consumed (type='out') inside the window.
 * Same COUNT(*) as recentPopularProducts(), so the dashboard and the rule agree.
 */
function autoFavoriteUseCount(PDO $db, int $productId): int {
    if ($productId <= 0) {
        return 0;
    }
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM transactions
         WHERE product_id = ? AND type = 'out' AND undone = 0
           AND created_at >= datetime('now', '-' || ? || ' days')"
    );
    $stmt->execute([$productId, autoFavoriteWindowDays()]);
    return (int)$stmt->fetchColumn();
}

/**
 * Absolute top-N product ids by consumption in the window (meeting the min-uses
 * floor). Rank is global — already-starred and vetoed products still occupy a
 * slot so a 4th contender cannot sneak in behind them.
 *
 * @return list<int>
 */
function autoFavoriteTopProductIds(PDO $db): array {
    $min = autoFavoriteMinUses();
    if ($min <= 0) {
        return [];
    }
    $topN = autoFavoriteTopN();
    $stmt = $db->prepare(
        "SELECT p.id FROM products p
         WHERE (SELECT COUNT(*) FROM transactions t
                WHERE t.product_id = p.id AND t.type = 'out' AND t.undone = 0
                  AND t.created_at >= datetime('now', '-' || ? || ' days')) >= CAST(? AS INTEGER)
         ORDER BY (SELECT COUNT(*) FROM transactions t
                   WHERE t.product_id = p.id AND t.type = 'out' AND t.undone = 0
                     AND t.created_at >= datetime('now', '-' || ? || ' days')) DESC,
                  p.id ASC
         LIMIT " . (int)$topN
    );
    $stmt->execute([autoFavoriteWindowDays(), $min, autoFavoriteWindowDays()]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Promote a top-N product to favourite.
 *
 * @return bool true when the star was actually added
 */
function maybeAutoFavorite(PDO $db, int $productId): bool {
    $min = autoFavoriteMinUses();
    if ($min <= 0 || $productId <= 0) {
        return false;
    }

    try {
        $stmt = $db->prepare(
            'SELECT COALESCE(is_favorite, 0) AS is_favorite,
                    COALESCE(favorite_user_override, 0) AS favorite_user_override,
                    name
             FROM products WHERE id = ?'
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ((int)$row['is_favorite'] === 1 || (int)$row['favorite_user_override'] === 1) {
            return false;
        }

        $uses = autoFavoriteUseCount($db, $productId);
        if ($uses < $min) {
            return false;
        }

        $topIds = autoFavoriteTopProductIds($db);
        if (!in_array($productId, $topIds, true)) {
            return false;
        }

        $db->prepare(
            'UPDATE products SET is_favorite = 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND COALESCE(favorite_user_override, 0) = 0'
        )->execute([$productId]);

        EverLog::info('auto favourite promoted', [
            'event'       => 'auto_favorite',
            'product_id'  => $productId,
            'name'        => (string)$row['name'],
            'uses'        => $uses,
            'min_uses'    => $min,
            'top_n'       => autoFavoriteTopN(),
            'rank_ids'    => $topIds,
            'window_days' => autoFavoriteWindowDays(),
        ]);
        return true;
    } catch (Throwable $e) {
        EverLog::warn('auto favourite skipped', [
            'event'      => 'auto_favorite_error',
            'product_id' => $productId,
            'error'      => $e->getMessage(),
        ]);
        return false;
    }
}

/**
 * Remember a manual star/unstar so the automatic rule stops touching the product.
 * Starring by hand means "keep it here"; unstarring means "leave it alone".
 */
function rememberFavoriteOverride(PDO $db, int $productId, bool $isFavorite): void {
    if ($productId <= 0) {
        return;
    }
    $db->prepare('UPDATE products SET favorite_user_override = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([$isFavorite ? 0 : 1, $productId]);
}

/**
 * Products that qualify for automatic promotion right now: in the absolute top N,
 * not a favourite yet, no manual veto. Used by the sweep and by its dry-run preview.
 *
 * @return list<int>
 */
function autoFavoriteCandidates(PDO $db): array {
    $min = autoFavoriteMinUses();
    if ($min <= 0) {
        return [];
    }
    $out = [];
    foreach (autoFavoriteTopProductIds($db) as $id) {
        $stmt = $db->prepare(
            'SELECT COALESCE(is_favorite, 0) AS is_favorite,
                    COALESCE(favorite_user_override, 0) AS favorite_user_override
             FROM products WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }
        if ((int)$row['is_favorite'] === 1 || (int)$row['favorite_user_override'] === 1) {
            continue;
        }
        $out[] = $id;
    }
    return $out;
}

/**
 * One-pass sweep over every product that qualifies — used by the "apply rules to
 * existing items" maintenance action. Never touches a product the user vetoed.
 *
 * @return int number of products promoted
 */
function autoFavoriteSweep(PDO $db): int {
    $promoted = 0;
    foreach (autoFavoriteCandidates($db) as $id) {
        if (maybeAutoFavorite($db, $id)) {
            $promoted++;
        }
    }
    return $promoted;
}

/** Count-only companion of autoFavoriteSweep() for the dry-run preview. */
function autoFavoriteSweepPreview(PDO $db): int {
    return count(autoFavoriteCandidates($db));
}
