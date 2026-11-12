<?php
/**
 * EverShelf API bootstrap — shared by HTTP router and cron.
 */
require_once __DIR__ . '/lib/env.php';
loadEnv(); // read .env first: bridges it into getenv()/$_ENV before anything reads config

// Never emit HTML notices before JSON API responses (breaks fetch().json() in the PWA).
if (!defined('CRON_MODE') && env('DISPLAY_ERRORS') !== '1') {
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
}
require_once __DIR__ . '/lib/constants.php';
require_once __DIR__ . '/lib/github.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/mealie.php';
require_once __DIR__ . '/lib/mealie_setup.php';
require_once __DIR__ . '/lib/cron_log.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/lib/shopping_guards.php';
require_once __DIR__ . '/lib/sale_units.php';
// The vocabulary layer: product names/genres/brands/plurals are DATA (api/vocab/<lang>.json),
// this lib only matches them. Loaded before product_kind.php, which reads its dictionaries.
require_once __DIR__ . '/lib/vocabulary.php';
// Product rules: the genre (genere) that leads every article title, and the
// automatic promotion of the products the household uses most.
require_once __DIR__ . '/lib/product_kind.php';
require_once __DIR__ . '/lib/auto_favorite.php';
require_once __DIR__ . '/lib/shopping_sync.php';
require_once __DIR__ . '/lib/pairing.php';
require_once __DIR__ . '/lib/health.php';
require_once __DIR__ . '/lib/weather.php';
require_once __DIR__ . '/lib/ai_provider.php';
require_once __DIR__ . '/lib/ai_prompts.php';
require_once __DIR__ . '/lib/seasonal.php';
require_once __DIR__ . '/lib/opened_shelf_life.php';
// The household's own PAO (period after opening): the two numbers the notice strip prints can
// be edited, and the value used is the AVERAGE of the corrections for that family in that place.
require_once __DIR__ . '/lib/pao_overrides.php';
require_once __DIR__ . '/lib/medical.php';
// The medicine catalogue (AIFA open data → local SQLite table, imported by
// scripts/import-medicines.php --country=IT). Identification only: no dose, no advice.
require_once __DIR__ . '/lib/medicines.php';
require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/calendar_ics.php';
require_once __DIR__ . '/lib/recipe_shopping.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/telegram.php';
require_once __DIR__ . '/lib/healthcheck.php';
require_once __DIR__ . '/lib/open_prices.php';
require_once __DIR__ . '/lib/webpush.php';
// Support layer: the optional donation/banner question (see api/lib/support.php).
require_once __DIR__ . '/lib/support.php';

// ── HTTP-only hardening ───────────────────────────────────────────────────────
// Neither of these means anything to a cron job or a script: they judge a REQUEST.
if (!defined('CRON_MODE') && PHP_SAPI !== 'cli') {
    // 1. The DNS-rebinding guard: a Host we do not answer to is refused (opt-in — the names
    //    an installation uses are not knowable from the code, see ALLOWED_HOSTS).
    evershelfEnforceAllowedHosts();
    // 2. An install with no API_TOKEN used to answer the whole LAN, unauthenticated. Mint one
    //    on the first request and enforce it from here on (ALLOW_OPEN_LAN=true opts out), so
    //    the household that never read SECURITY.md is not the one running an open pantry.
    evershelfEnsureApiToken();
}
