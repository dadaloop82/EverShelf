<?php
/**
 * EverShelf API bootstrap — shared by HTTP router and cron.
 */
require_once __DIR__ . '/lib/env.php';
require_once __DIR__ . '/lib/constants.php';
require_once __DIR__ . '/lib/github.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/cron_log.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/database.php';
