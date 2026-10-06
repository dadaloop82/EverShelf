<?php
/**
 * EverShelf — automatic favourites.
 *
 * The products the household actually uses must end up among the favourites
 * without anyone starring them by hand. A product consumed
 * autoFavoriteMinUses() times inside the recent window (the same 90-day window
 * recentPopularProducts() uses for its "usage_count") is promoted to favourite on
 * the next use.
 *
 * Two guards keep the automatic rule from fighting the user:
 *   * products.favorite_user_override = 1 as soon as the user unstars a favourite,
 *     so a manual decision always wins and the rule never re-adds it,
 *   * the rule only ever ADDS favourites — it never removes one.
 *
 * Config: AUTO_FAVORITE_MIN_USES (default 3, 0 = off), AUTO_FAVORITE_WINDOW_DAYS (90).
 */

/** How many consumptions promote a product to favourite (0 disables the feature). */
function autoFavoriteMinUses(): int {
    return max(0, (int)env('AUTO_FAVORITE_MIN_USES', '3'));
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
 * Promote a frequently used product to favourite.
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
 * Products that qualify for automatic promotion right now: not a favourite yet and
 * no manual veto. Used by the sweep and by its dry-run preview.
 *
 * @return list<int>
 */
function autoFavoriteCandidates(PDO $db): array {
    $min = autoFavoriteMinUses();
    if ($min <= 0) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT p.id FROM products p
         WHERE COALESCE(p.is_favorite, 0) = 0
           AND COALESCE(p.favorite_user_override, 0) = 0
           AND (SELECT COUNT(*) FROM transactions t
                WHERE t.product_id = p.id AND t.type = 'out' AND t.undone = 0
                  AND t.created_at >= datetime('now', '-' || ? || ' days')) >= CAST(? AS INTEGER)"
    );
    $stmt->execute([autoFavoriteWindowDays(), $min]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
