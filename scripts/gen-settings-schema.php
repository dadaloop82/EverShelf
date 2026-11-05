<?php
/**
 * Generate the settings registry from the page that actually renders it.
 *
 * The settings page grew to ~40 cards and ~140 controls across 16 tabs, with the
 * same setting written in four places (index.html, get_settings(), the
 * saveSettings() key map, .env.example). This script reads the markup and writes
 * ONE registry used by the search bar, the setup checklist and the guard test:
 *
 *   assets/js/settings.schema.generated.js   (browser, offline-safe)
 *   api/lib/settings_schema.php              (server/tests)
 *
 * It never invents an entry: a control that is not in index.html is not in the
 * registry; the two must not drift apart.
 *
 * Usage: php scripts/gen-settings-schema.php [--check]
 *   --check  exit 1 when the generated files are stale (CI-friendly)
 */

$root = dirname(__DIR__);
$check = in_array('--check', $argv ?? [], true);

$html = (string)file_get_contents($root . '/index.html');
$envExample = (string)file_get_contents($root . '/.env.example');

preg_match_all('/^([A-Z][A-Z0-9_]+)=/m', $envExample, $m);
$envKeys = array_values(array_unique($m[1] ?? []));

// ---------------------------------------------------------------- the region
$start = strpos($html, 'id="page-settings"');
if ($start === false) {
    fwrite(STDERR, "page-settings not found\n");
    exit(1);
}
$nextSection = preg_match('/\n\s*<section class="page"/', substr($html, $start), $sm, PREG_OFFSET_CAPTURE);
$end = $nextSection ? $start + $sm[0][1] : false;
$region = $end !== false ? substr($html, $start, $end - $start) : substr($html, $start);

// tab -> section, from the tab strip
$tabGroup = [];
preg_match_all('/<button[^>]*data-group="([a-z]+)"[^>]*data-tab="(tab-[a-z-]+)"/', $html, $m);
foreach ($m[1] as $i => $group) {
    $tabGroup[$m[2][$i]] = $group;
}

// ------------------------------------------------- split the region by panel
$panels = [];
preg_match_all('/<div class="settings-panel[^"]*" id="(tab-[a-z-]+)">/', $region, $pm, PREG_OFFSET_CAPTURE);
$count = count($pm[1]);
for ($i = 0; $i < $count; $i++) {
    $id = $pm[1][$i][0];
    $from = $pm[0][$i][1];
    $to = $i + 1 < $count ? $pm[0][$i + 1][1] : strlen($region);
    $panels[] = ['tab' => $id, 'body' => substr($region, $from, $to - $from)];
}

/** Human-ish keywords for the search: env key => i18n key with comma-separated synonyms. */
$keywordKeys = [
    'TAGS_ENABLED'             => 'settings.search_kw.tags',
    'PRODUCT_VARIANTS_ENABLED' => 'settings.search_kw.family',
    'PRODUCT_KIND_PREFIX'      => 'settings.search_kw.kind',
    'AUTO_FAVORITE_MIN_USES'   => 'settings.search_kw.favorites',
    'AUDIT_MODE_BATCH_SIZE'    => 'settings.search_kw.audit',
    'RECEIPT_OCR_ENABLED'      => 'settings.search_kw.receipt',
    'GEMINI_API_KEY'           => 'settings.search_kw.ai',
    'AI_PROVIDER'              => 'settings.search_kw.ai',
    'OPENAI_API_KEY'           => 'settings.search_kw.ai',
    'SHOPPING_MODE'            => 'settings.search_kw.shopping',
    'BRING_EMAIL'              => 'settings.search_kw.shopping',
    'SHOPPING_REMOVE_ON_BUY'   => 'settings.search_kw.shopping',
    'NTFY_URL'                 => 'settings.search_kw.notify',
    'TELEGRAM_BOT_TOKEN'       => 'settings.search_kw.notify',
    'HA_URL'                   => 'settings.search_kw.ha',
    'DARK_MODE'                => 'settings.search_kw.theme',
    'APP_LANG'                 => 'settings.search_kw.language',
    'BACKUP_SCHEDULE'          => 'settings.search_kw.backup',
];

/** Control id -> .env key, when the two do not share the slug. */
$idToEnv = [
    'setting-gemini-key'          => 'GEMINI_API_KEY',
    'setting-audit-batch-size'    => 'AUDIT_MODE_BATCH_SIZE',
    'setting-scale-url'           => 'SCALE_GATEWAY_URL',
    'setting-telegram-token'      => 'TELEGRAM_BOT_TOKEN',
    'setting-telegram-chats'      => 'TELEGRAM_ALLOWED_CHAT_IDS',
    'setting-mealie-sync-days'    => 'MEALIE_CACHE_SYNC_DAYS',
];

/** Controls nested in the same block as another control: listed explicitly. */
$extraEntries = [
    [
        'id'        => 'setting-audit-batch-size',
        'env'       => 'AUDIT_MODE_BATCH_SIZE',
        'tab'       => 'tab-general',
        'label'     => 'settings.product_rules.audit_batch_label',
        'hint'      => '',
        'card'      => 'settings.product_rules.card_title',
        'card_hint' => 'settings.product_rules.card_hint',
        'keywords'  => 'settings.search_kw.audit',
        'type'      => 'int',
    ],
];

$entries = [];
$missingHint = [];
$review = [];

foreach ($panels as $panel) {
    $tab = $panel['tab'];
    $group = $tabGroup[$tab] ?? '';
    $body = $panel['body'];

    // Cards give every control a fallback description, so a toggle does not need
    // its own hint when the card already explains the feature.
    $cards = [];
    preg_match_all('/<h4[^>]*data-i18n="([^"]+)"[^>]*>.*?<p class="settings-hint" data-i18n="([^"]+)"/s', $body, $ckeys, PREG_OFFSET_CAPTURE);
    foreach ($ckeys[0] as $ci => $cHit) {
        $cards[] = ['at' => $cHit[1], 'title' => $ckeys[1][$ci][0], 'hint' => $ckeys[2][$ci][0]];
    }

    if (!preg_match_all('/class="form-group/', $body, $fm, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($fm[0] as $i => $hit) {
        $card = ['title' => '', 'hint' => ''];
        foreach ($cards as $candidate) {
            if ($candidate['at'] < $hit[1]) {
                $card = $candidate;
            }
        }
        $to = isset($fm[0][$i + 1]) ? $fm[0][$i + 1][1] : strlen($body);
        $block = substr($body, $hit[1], max(0, $to - $hit[1]));

        // A container (a <div> wrapping a label) is not a control: take the id of
        // the input/select/textarea/button inside the block, if any.
        $id = '';
        if (preg_match('/<(?:input|select|textarea)[^>]*\bid="([a-z0-9][a-z0-9-]*)"/', $block, $im)
            || preg_match('/<button[^>]*\bid="([a-z0-9][a-z0-9-]*)"/', $block, $im)) {
            $id = $im[1];
        }
        // Not settings: the custom-location input lives here but it is user data.
        $notSettings = ['new-custom-location-input'];
        if ($id === '' || in_array($id, $notSettings, true)
            || strpos($id, 'tab-') === 0 || $id === 'settings-search') {
            continue;
        }
        if (!preg_match('/data-i18n="([^"]+)"/', $block, $lm)) {
            $review[] = "$tab/$id: no label key";
            continue;
        }
        $label = $lm[1];
        $hint = '';
        if (preg_match('/class="settings-hint"[^>]*data-i18n="([^"]+)"|data-i18n="([^"]+)"[^>]*class="settings-hint"/', $block, $hm)) {
            $hint = $hm[1] !== '' ? $hm[1] : $hm[2];
        }
        if ($hint === '' && $card['hint'] === '') {
            $missingHint[] = "$tab/$id";
        }
        $type = 'text';
        if (strpos($block, 'type="checkbox"') !== false) {
            $type = 'bool';
        } elseif (strpos($block, 'type="number"') !== false) {
            $type = 'int';
        } elseif (strpos($block, 'type="password"') !== false) {
            $type = 'secret';
        } elseif (strpos($block, '<select') !== false) {
            $type = 'select';
        } elseif (strpos($block, 'type="file"') !== false) {
            $type = 'file';
        }
        $slug = preg_replace('/^setting-/', '', $id);
        $env = $idToEnv[$id] ?? strtoupper(str_replace('-', '_', $slug));
        if (!in_array($env, $envKeys, true)) {
            $env = '';
        }
        if (isset($entries[$id])) {
            $review[] = "$tab/$id: duplicate id";
            continue;
        }
        $entries[$id] = [
            'id'       => $id,
            'env'      => $env,
            'tab'      => $tab,
            'group'    => $group,
            'label'    => $label,
            'hint'     => $hint,
            'card'     => $card['title'],
            'card_hint' => $card['hint'],
            'keywords' => $env !== '' && isset($keywordKeys[$env]) ? $keywordKeys[$env] : '',
            'type'     => $type,
        ];
    }
}

// Sweep: controls that do not sit inside a .form-group (toggle grids, grouped
// fields) must still be findable, so nothing on the page is invisible to search.
$notSettings = ['new-custom-location-input'];
foreach ($panels as $panel) {
    preg_match_all('/id="(setting-[a-z0-9-]+)"/', $panel['body'], $allIds, PREG_OFFSET_CAPTURE);
    foreach ($allIds[1] as $k => $hitId) {
        $id = $hitId[0];
        if (isset($entries[$id]) || in_array($id, $notSettings, true)) {
            continue;
        }
        $at = $allIds[0][$k][1];
        $before = substr($panel['body'], 0, $at);
        preg_match_all('/data-i18n="([^"]+)"/', $before, $labelAll, PREG_OFFSET_CAPTURE);
        $label = $labelAll[1] ? end($labelAll[1])[0] : '';
        if ($label === '') {
            $review[] = $panel['tab'] . "/$id: no label key";
            continue;
        }
        preg_match_all('/<h4[^>]*data-i18n="([^"]+)"[^>]*>.*?<p class="settings-hint" data-i18n="([^"]+)"/s', $before, $cardAll, PREG_SET_ORDER);
        $card = $cardAll ? end($cardAll) : null;
        $env = $idToEnv[$id] ?? strtoupper(str_replace('-', '_', preg_replace('/^setting-/', '', $id)));
        if (!in_array($env, $envKeys, true)) {
            $env = '';
        }
        $entries[$id] = [
            'id' => $id, 'env' => $env,
            'tab' => $panel['tab'], 'group' => $tabGroup[$panel['tab']] ?? '',
            'label' => $label, 'hint' => '',
            'card' => $card ? $card[1] : '', 'card_hint' => $card ? $card[2] : '',
            'keywords' => $env !== '' && isset($keywordKeys[$env]) ? $keywordKeys[$env] : '',
            'type' => 'action',
        ];
        if ($card === null) {
            $missingHint[] = $panel['tab'] . "/$id";
        }
    }
}

$list = array_values($entries);
foreach ($extraEntries as $extra) {
    $list[] = [
        'id' => $extra['id'], 'env' => $extra['env'],
        'tab' => $extra['tab'], 'group' => $tabGroup[$extra['tab']] ?? '',
        'label' => $extra['label'], 'hint' => $extra['hint'],
        'card' => $extra['card'], 'card_hint' => $extra['card_hint'],
        'keywords' => $extra['keywords'], 'type' => $extra['type'],
    ];
}

require_once $root . '/api/lib/settings_env_allowlist.php';
$allowlist = evershelfSettingsEnvAllowlist();
$mapped = array_filter(array_column($list, 'env'));
$envOnly = [];
foreach ($envKeys as $key) {
    if (in_array($key, $mapped, true)) {
        continue;
    }
    if (!isset($allowlist[$key])) {
        $review[] = "$key: no control and no allowlist reason";
        continue;
    }
    $envOnly[] = ['env' => $key, 'reason' => $allowlist[$key]];
}

// ----------------------------------------------------------------- write out
$js = "/* GENERATED by scripts/gen-settings-schema.php — do not edit by hand.\n"
    . " * One entry per control of the settings page: label/hint are i18n keys, so the\n"
    . " * search bar reads them through t() and the guard test checks them in all six\n"
    . " * locales. Regenerate after editing index.html. */\n"
    . "window.EVERSHELF_SETTINGS_SCHEMA = " . json_encode([
        'tabs'      => $tabGroup,
        'entries'   => $list,
        'env_only'  => $envOnly,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n";

$php = "<?php\n/**\n * GENERATED by scripts/gen-settings-schema.php — do not edit by hand.\n */\nfunction evershelfSettingsSchema(): array\n{\n    return "
    . var_export(['tabs' => $tabGroup, 'entries' => $list, 'env_only' => $envOnly], true) . ";\n}\n";

$jsPath = $root . '/assets/js/settings.schema.generated.js';
$phpPath = $root . '/api/lib/settings_schema.php';

if ($check) {
    $same = is_file($jsPath) && file_get_contents($jsPath) === $js
        && is_file($phpPath) && file_get_contents($phpPath) === $php;
    echo $same ? "settings schema is up to date\n" : "settings schema is stale — run php scripts/gen-settings-schema.php\n";
    exit($same ? 0 : 1);
}

file_put_contents($jsPath, $js);
file_put_contents($phpPath, $php);
echo 'wrote ' . count($list) . ' controls, ' . count($envOnly) . " env-only keys\n";
if ($review) {
    echo "REVIEW:\n  " . implode("\n  ", $review) . "\n";
}
if ($missingHint) {
    echo 'NO HINT (' . count($missingHint) . "):\n  " . implode("\n  ", $missingHint) . "\n";
}

