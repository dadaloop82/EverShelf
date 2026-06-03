<?php
/**
 * Rotate data/cron.log — keep last N MB / lines.
 */

require_once __DIR__ . '/constants.php';

function evershelfRotateCronLog(?int $maxBytes = null, int $keepRotated = 3): void {
    $path = CRON_LOG_PATH;
    if (!file_exists($path)) {
        return;
    }
    $maxBytes = $maxBytes ?? max(65536, (int)env('CRON_LOG_MAX_BYTES', '524288'));
    $size = filesize($path);
    if ($size === false || $size <= $maxBytes) {
        return;
    }
    for ($i = $keepRotated; $i >= 1; $i--) {
        $from = ($i === 1) ? $path : $path . '.' . ($i - 1);
        $to   = $path . '.' . $i;
        if ($i === $keepRotated && file_exists($to)) {
            @unlink($to);
        }
        if (file_exists($from)) {
            @rename($from, $to);
        }
    }
}
