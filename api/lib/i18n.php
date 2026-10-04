<?php
/**
 * EverShelf — server-side translation lookup.
 *
 * The frontend uses t('key') / data-i18n in assets/js/app.js. A few responses are
 * produced by PHP (the ICS expiry feed today, the notifier tomorrow) and still need
 * real translations, so this is the smallest honest equivalent: read
 * translations/<lang>.json, walk the dotted key, fall back to English, then to the
 * key itself (which stays greppable in logs/output).
 *
 * Keys resolved through evershelfTr() must exist in all six locales; scripts/i18n-audit.py
 * counts them as used (see its SRC_PHP scan).
 */

/**
 * @param array<string,string|int|float> $args {placeholder} replacements
 */
function evershelfTr(string $key, string $lang = 'en', array $args = []): string {
    static $cache = [];

    if (!preg_match('/^[a-z]{2}$/', $lang)) {
        $lang = 'en';
    }
    $value = '';
    foreach (array_unique([$lang, 'en', 'it']) as $l) {
        if (!isset($cache[$l])) {
            $file = dirname(__DIR__, 2) . '/translations/' . $l . '.json';
            $decoded = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
            $cache[$l] = is_array($decoded) ? $decoded : [];
        }
        $node = $cache[$l];
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                $node = null;
                break;
            }
            $node = $node[$part];
        }
        if (is_string($node) && $node !== '') {
            $value = $node;
            break;
        }
    }
    if ($value === '') {
        return $key;
    }
    foreach ($args as $k => $v) {
        $value = str_replace('{' . $k . '}', (string)$v, $value);
    }
    return $value;
}
