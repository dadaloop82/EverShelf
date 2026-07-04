<?php
/**
 * EverShelf API bootstrap — shared by HTTP router and cron.
 */
// Never emit HTML notices before JSON API responses (breaks fetch().json() in the PWA).
if (!defined('CRON_MODE') && (getenv('DISPLAY_ERRORS') ?: '') !== '1') {
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
}
require_once __DIR__ . '/lib/env.php';
require_once __DIR__ . '/lib/constants.php';
require_once __DIR__ . '/lib/github.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/mealie.php';
require_once __DIR__ . '/lib/cron_log.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/database.php';
