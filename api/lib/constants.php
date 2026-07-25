<?php
/**
 * EverShelf — shared path constants.
 */

define('EVERSHELF_ROOT', dirname(__DIR__, 2));
define('GH_REPO', 'dadaloop82/EverShelf');
define('PRICE_CACHE_PATH',         EVERSHELF_ROOT . '/data/shopping_price_cache.json');
define('CATEGORY_CACHE_PATH',      EVERSHELF_ROOT . '/data/category_ai_cache.json');
define('SHELF_CACHE_PATH',         EVERSHELF_ROOT . '/data/opened_shelf_cache.json');
define('FOODFACTS_CACHE_PATH',     EVERSHELF_ROOT . '/data/food_facts_cache.json');
define('SHOPPING_NAME_CACHE_PATH', EVERSHELF_ROOT . '/data/shopping_name_cache.json');
define('BRING_TOKEN_PATH',         EVERSHELF_ROOT . '/data/bring_token.json');
define('AI_USAGE_PATH',            EVERSHELF_ROOT . '/data/ai_usage.json');
define('BACKUP_DIR',               EVERSHELF_ROOT . '/data/backups');
define('BACKUP_LAST_TS_PATH',      EVERSHELF_ROOT . '/data/backup_last_ts.json');
define('MEALIE_CACHE_PATH',        EVERSHELF_ROOT . '/data/mealie_cache.json');
define('CRON_LOG_PATH',            EVERSHELF_ROOT . '/data/cron.log');

define('GEMINI_COST_25F_IN',  (float)(getenv('GEMINI_COST_25F_IN')  ?: 0.15));
define('GEMINI_COST_25F_OUT', (float)(getenv('GEMINI_COST_25F_OUT') ?: 0.60));
define('GEMINI_COST_20F_IN',  (float)(getenv('GEMINI_COST_20F_IN')  ?: 0.10));
define('GEMINI_COST_20F_OUT', (float)(getenv('GEMINI_COST_20F_OUT') ?: 0.40));
/** Gemini 3.5 Flash official paid rates ($/1M tokens, Jul 2026). */
define('GEMINI_COST_35F_IN',  (float)(getenv('GEMINI_COST_35F_IN')  ?: 1.50));
define('GEMINI_COST_35F_OUT', (float)(getenv('GEMINI_COST_35F_OUT') ?: 9.00));
/** Max Gemini shopping-name classify calls per calendar day (hard cap). */
define('GEMINI_CLASSIFY_DAILY_MAX', (int)(getenv('GEMINI_CLASSIFY_DAILY_MAX') ?: 40));
define('GEMINI_CLASSIFY_DAY_PATH', EVERSHELF_ROOT . '/data/classify_daily.json');
define('GEMINI_CLASSIFY_NEG_TTL', 7 * 86400);
