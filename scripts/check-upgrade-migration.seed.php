<?php
/**
 * EverShelf — the legacy content the upgrade check fills a database with.
 *
 * Used by scripts/check-upgrade-migration.php: it runs against the OLD schema (only the
 * columns that exist there are inserted), and it deliberately contains the awkward cases an
 * upgrade has to survive — hand-typed dates, rows with no date at all, stock in the retired
 * "altro" place, titles stored with escaped HTML entities, a legacy `env_overrides` blob,
 * duplicate-free recipes, tags, health rows.
 *
 * Add a row here when a release introduces a new kind of user data: the check then keeps
 * proving that an older installation survives the update.
 */

declare(strict_types=1);

function checkSeedLegacy(PDO $db): void
{
    $cats   = ['latticini', 'carne', 'pesce', 'frutta', 'verdura', 'pane', 'conserve', 'bevande', 'altro'];
    $units  = ['pz', 'g', 'ml', 'kg', 'conf'];
    $locs   = ['dispensa', 'dispensa', 'frigo', 'frigo', 'freezer', 'cantina', 'altro'];
    $names  = ['Latte fresco', 'Yogurt bianco', 'Pasta di semola', 'Riso carnaroli', 'Pomodorini',
               'Passata di pomodoro', 'Petto di pollo', 'Tonno in scatola', 'Pane integrale',
               'Olio extravergine', 'Caffè macinato', 'Biscotti secchi', 'Mozzarella', 'Uova medie'];
    // Titles an old import stored escaped: the new version decodes them on boot.
    $escaped = [
        'Marmellata di &amp;quot;Limone&amp;quot;',
        'Caffè &amp;amp; Latte',
        "Succo d&amp;#39;arancia",
    ];

    $productIds = [];
    for ($i = 0; $i < 120; $i++) {
        $isEscaped = $i < count($escaped);
        checkInsertRow($db, 'products', [
            'barcode'          => $i % 3 === 0 ? null : sprintf('80%011d', 1000000 + $i),
            'name'             => $isEscaped ? $escaped[$i] : ($names[$i % count($names)] . ' ' . ($i + 1)),
            'brand'            => $i % 4 === 0 ? 'Mulino Bianco' : '',
            'category'         => $cats[$i % count($cats)],
            'image_url'        => $i % 5 === 0 ? 'https://images.openfoodfacts.org/x' . $i . '.jpg' : '',
            'unit'             => $units[$i % count($units)],
            'default_quantity' => [1, 500, 1000, 6][$i % 4],
            'notes'            => '',
            'shopping_name'    => $i % 2 === 0 ? 'Famiglia ' . $i : '',
            'parent_id'        => null,
            'kind'             => $i % 3 === 0 ? 'latticini' : '',
            'name_user_set'    => $i % 7 === 0 ? 1 : 0,
            'package_unit'     => $i % 5 === 4 ? 'ml' : '',
            'is_favorite'      => $i % 9 === 0 ? 1 : 0,
            'favorite_user_override' => 0,
            'nutriments_json'  => $i % 6 === 0 ? '{"energy-kcal":250}' : '',
            'created_at'       => sprintf('2026-%02d-%02d 10:00:00', 1 + ($i % 9), 1 + ($i % 27)),
        ]);
        $productIds[] = (int)$db->lastInsertId();
    }

    for ($i = 0; $i < 180; $i++) {
        $unit = $units[$i % count($units)];
        checkInsertRow($db, 'inventory', [
            'product_id'      => $productIds[$i % count($productIds)],
            'location'        => $locs[$i % count($locs)],
            'quantity'        => $unit === 'g' ? 250.0 + $i : ($unit === 'ml' ? 500.0 : 1 + ($i % 4)),
            // one row in six has NO best-before date at all (must be dated by the upgrade)
            'expiry_date'     => $i % 6 === 0 ? null : sprintf('2026-%02d-%02d', 1 + ($i % 12), 1 + ($i % 28)),
            'added_at'        => sprintf('2026-%02d-%02d 09:30:00', 1 + ($i % 9), 1 + ($i % 27)),
            'updated_at'      => sprintf('2026-%02d-%02d 09:30:00', 1 + ($i % 9), 1 + ($i % 27)),
            'expiry_user_set' => $i % 4 === 0 ? 1 : 0,   // dates typed by the household
            'opened_at'       => $i % 5 === 0 ? '2026-10-20 18:00:00' : null,
            'vacuum_sealed'   => $i % 8 === 0 ? 1 : 0,
        ]);
    }

    for ($i = 0; $i < 500; $i++) {
        checkInsertRow($db, 'transactions', [
            'product_id' => $productIds[$i % count($productIds)],
            'type'       => ['in', 'out', 'waste'][$i % 3],
            'quantity'   => 1 + ($i % 5),
            'location'   => $locs[$i % count($locs)],
            'notes'      => $i % 11 === 0 ? 'Nota con &amp;quot;virgolette&amp;quot;' : '',
            'undone'     => $i % 17 === 0 ? 1 : 0,
            'created_at' => sprintf('2026-%02d-%02d 12:%02d:00', 1 + ($i % 9), 1 + ($i % 27), $i % 60),
        ]);
    }

    for ($i = 0; $i < 30; $i++) {
        checkInsertRow($db, 'recipes', [
            'date'         => sprintf('2026-09-%02d', 1 + $i),
            'meal'         => ['pranzo', 'cena'][$i % 2],
            'recipe_json'  => '{"title":"Ricetta ' . ($i + 1) . '","steps":["1. Cuoci"]}',
            'created_at'   => sprintf('2026-09-%02d 20:00:00', 1 + $i),
            'content_hash' => $i % 2 === 0 ? md5('Ricetta ' . ($i + 1)) : '',
            'is_favorite'  => $i % 6 === 0 ? 1 : 0,
        ]);
    }

    for ($i = 0; $i < 40; $i++) {
        checkInsertRow($db, 'shopping_list', [
            'name'       => 'Da comprare ' . ($i + 1),
            'need_qty'   => 1 + ($i % 3),
            'need_unit'  => $units[$i % count($units)],
            'is_checked' => $i % 5 === 0 ? 1 : 0,
            'sort_order' => $i,
            'added_at'   => strtotime('2026-10-0' . (1 + ($i % 8)) . ' 08:00:00'),
        ]);
    }

    for ($i = 0; $i < 20; $i++) {
        checkInsertRow($db, 'chat_messages', [
            'role'       => $i % 2 === 0 ? 'user' : 'assistant',
            'text'       => 'Messaggio ' . ($i + 1),
            'created_at' => '2026-10-10 10:' . sprintf('%02d', $i) . ':00',
        ]);
    }

    $settings = [
        'shopping_mode'               => 'internal',
        'shopping_removed_block_mode' => 'month',
        'shopping_removed_block_days' => '14',
        'shopping_dismissed'          => '{"latte":"2026-10-01"}',
        'setup_done'                  => '1',
        'setup_seen'                  => '{"welcome":1}',
        'med_setup_done'              => '0',
        'gemini_model'                => 'gemini-2.0-flash',
        'app_lang'                    => 'it',
        'currency'                    => 'EUR',
        'expiry_warning_days'         => '7',
        'dashboard_expiring_max'      => '5',
        'shopping_plan_days'          => '7',
        'audit_mode'                  => 'propose',
        'env_overrides'               => '{"GEMINI_API_KEY":"AIza-legacy"}',
        'backup_enabled'              => '1',
        'notify_provider'             => 'ntfy',
        'seasonal_enabled'            => '1',
        'location_icons'              => '{}',
    ];
    foreach ($settings as $k => $v) {
        checkInsertRow($db, 'app_settings', ['key' => $k, 'value' => $v]);
    }

    for ($i = 0; $i < 40; $i++) {
        checkInsertRow($db, 'barcode_cache', [
            'barcode'    => sprintf('80%011d', 2000000 + $i),
            'found'      => $i % 2,
            'source'     => 'off',
            'payload'    => '{"name":"Prodotto ' . $i . '"}',
            'updated_at' => '2026-10-01 10:00:00',
        ]);
        checkInsertRow($db, 'barcode_catalog', [
            'barcode' => sprintf('80%011d', 3000000 + $i),
            'name'    => 'Catalogo ' . $i,
        ]);
    }

    checkInsertRow($db, 'shopping_templates', ['name' => 'Spesa settimanale', 'items_json' => '["latte","pane"]']);
    checkInsertRow($db, 'shopping_templates', ['name' => 'Pulizie', 'items_json' => '["detersivo"]']);

    $tagIds = [];
    for ($i = 0; $i < 8; $i++) {
        checkInsertRow($db, 'product_tags', ['name' => 'Tag ' . $i, 'name_key' => 'tag-' . $i]);
        $tagIds[] = (int)$db->lastInsertId();
    }
    for ($i = 0; $i < 30; $i++) {
        checkInsertRow($db, 'product_tag_links', [
            'product_id' => $productIds[$i % count($productIds)],
            'tag_id'     => $tagIds[$i % count($tagIds)],
        ]);
    }

    checkInsertRow($db, 'health_profile', [
        'sex' => 'm', 'birth_year' => 1984, 'height_cm' => 178, 'weight_kg' => 74.5,
        'activity_default' => 'moderate', 'goal' => 'maintain', 'enabled' => 1,
        'updated_at' => '2026-10-01 10:00:00',
    ]);
    for ($i = 0; $i < 20; $i++) {
        checkInsertRow($db, 'health_daily', [
            'date'        => sprintf('2026-10-%02d', 1 + $i),
            'source'      => 'manual',
            'burned_kcal' => 1900 + $i,
            'steps'       => 6000 + $i * 10,
            'synced_at'   => '2026-10-01 10:00:00',
            'updated_at'  => '2026-10-01 10:00:00',
        ]);
    }
    for ($i = 0; $i < 2; $i++) {
        checkInsertRow($db, 'health_tokens', [
            'token_hash' => hash('sha256', 'legacy-token-' . $i),
            'label'      => 'Phone',
            'created_at' => '2026-09-01 10:00:00',
        ]);
    }
    for ($i = 0; $i < 5; $i++) {
        checkInsertRow($db, 'health_meals', [
            'date'       => sprintf('2026-10-%02d', 1 + $i),
            'meal'       => 'pranzo',
            'title'      => 'Pasto ' . $i,
            'kcal'       => 500 + $i,
            'created_at' => '2026-10-0' . (1 + $i) . ' 13:00:00',
        ]);
    }
    checkInsertRow($db, 'webpush_subscriptions', ['endpoint' => 'https://push.example/1', 'p256dh' => 'x', 'auth' => 'y']);
}
