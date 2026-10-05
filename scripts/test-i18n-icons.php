#!/usr/bin/env php
<?php
/**
 * Regression tests: a translated label must never show its icon twice
 * ("🔔 🔔 Push notifications").
 *
 * Regression history: translation values often carry their own leading emoji
 * (settings.notify.title = "🔔 Push notifications"). Code that has to add an icon
 * of its own used to prepend another one, so the guided assistant printed the topic
 * and the timer twice ("⏱️ ⏱️ Cron watchdog"), the settings save toasts showed
 * "✅ ✅ …", and a few dashboard buttons doubled the trash bin. A repeated icon
 * carries no information, so such a label must be built with iconLabel(icon, key),
 * which strips the emoji already inside the translation.
 *
 * A label may legitimately start with an emoji; what must never happen is
 *   * an emoji immediately before a `t('key')` whose value starts with the same
 *     emoji in any locale, unless the line goes through iconLabel()/_stripLeadingEmoji();
 *   * a markup element whose whole content is one emoji, followed on the same line
 *     by a data-i18n element with a value starting with that same emoji.
 *
 * It also asserts that the copy the dashboard needs to explain itself exists in all
 * six locales: the verdict format, the expiry date line, the "you still have X" line
 * and the fuel-badge target line. A missing key would surface as a raw key name.
 *
 * Run: php scripts/test-i18n-icons.php
 */
$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

$root = __DIR__ . '/..';

/** Flatten a locale JSON into dotted keys. */
function flatten(array $node, string $prefix = ''): array
{
    $out = [];
    foreach ($node as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (is_array($value)) {
            $out += flatten($value, $path);
        } else {
            $out[$path] = (string)$value;
        }
    }
    return $out;
}

$locales = ['it', 'en', 'de', 'fr', 'es', 'zh'];
$strings = [];
foreach ($locales as $loc) {
    $raw = (string)file_get_contents($root . '/translations/' . $loc . '.json');
    $strings[$loc] = flatten((array)json_decode($raw, true));
}

// Emoji ranges we care about (pictographs, dingbats, symbols, arrows, VS16).
$E = '\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}\x{FE0F}';
$SAFE = ['iconLabel(', '_stripLeadingEmoji'];

/** True when $value starts with the given emoji character. */
function starts_with_emoji(string $value, string $emoji): bool
{
    return $emoji !== '' && mb_strpos($value, $emoji) === 0;
}

// ── [1] app.js: emoji prepended to a translation that already has it ────────
$jsHits = 0;
foreach (explode("\n", (string)file_get_contents($root . '/assets/js/app.js')) as $i => $line) {
    $pattern = '/[\'"]\s*([' . $E . ']+)\s*[\'"]\s*(?:\+|\$\{)?\s*t\(\s*\'([a-zA-Z0-9_.]+)\''
        . '|([' . $E . ']+)\s*\$\{\s*t\(\s*\'([a-zA-Z0-9_.]+)\'/u';
    if (!preg_match_all($pattern, $line, $m, PREG_SET_ORDER)) {
        continue;
    }
    $safe = false;
    foreach ($SAFE as $marker) {
        if (str_contains($line, $marker)) {
            $safe = true;
        }
    }
    if ($safe) {
        continue;
    }
    foreach ($m as $hit) {
        $emoji = $hit[1] !== '' ? $hit[1] : ($hit[3] ?? '');
        $key   = $hit[2] !== '' ? $hit[2] : ($hit[4] ?? '');
        if ($key === '') {
            continue;
        }
        foreach ($strings as $loc => $dictionary) {
            $value = $dictionary[$key] ?? '';
            if (starts_with_emoji($value, $emoji)) {
                echo "FAIL: app.js line " . ($i + 1) . " prefixes '{$emoji}' to t('{$key}') but {$loc} already starts with it — use iconLabel()\n";
                $jsHits++;
                break;
            }
        }
    }
}

assert_true($jsHits === 0, 'no translation is prefixed with the emoji it already contains (app.js)');

// ── [2] index.html: standalone emoji element + data-i18n with the same emoji ─
$htmlHits = 0;
foreach (explode("\n", (string)file_get_contents($root . '/index.html')) as $i => $line) {
    if (!preg_match_all('/>(' . $E . '+)</u', $line, $em, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        continue;
    }
    if (!preg_match_all('/data-i18n(?:-title|-placeholder|-aria)?="([a-zA-Z0-9_.]+)"/', $line, $attrs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($em as $emojiNode) {
        $emoji  = $emojiNode[1][0];
        $offset = (int)$emojiNode[0][1];
        foreach ($attrs as $attr) {
            // Only when the icon element sits before the translatable element: an
            // element that is itself the data-i18n target is fine, because the
            // translation replaces the whole label.
            if ((int)$attr[0][1] < $offset) {
                continue;
            }
            foreach ($strings as $loc => $dictionary) {
                $value = $dictionary[$attr[1][0]] ?? '';
                if (starts_with_emoji($value, $emoji)) {
                    echo "FAIL: index.html line " . ($i + 1) . " shows '{$emoji}' next to data-i18n=\"{$attr[1][0]}\", whose {$loc} value starts with it\n";
                    $htmlHits++;
                    break 2;
                }
            }
        }
    }
}
assert_true($htmlHits === 0, 'no markup shows an icon next to a label that already has it (index.html)');

// ── [3] the dashboard copy must exist, with placeholders, in all locales ────
$required = [
    'dashboard.banner_advice_format'         => ['{label}', '{tip}'],
    'dashboard.banner_sold_date'             => ['{date}'],
    'dashboard.still_have_qty'               => ['{qty}'],
    'dashboard.banner_expired_action_extend' => [],
    'recipes.fuel_badge_target'              => ['{kcal}', '{protein}'],
];
foreach ($required as $key => $placeholders) {
    $problems = [];
    foreach ($locales as $loc) {
        $value = $strings[$loc][$key] ?? '';
        if ($value === '') {
            $problems[] = $loc . ' missing';
            continue;
        }
        foreach ($placeholders as $token) {
            if (!str_contains($value, $token)) {
                $problems[] = $loc . ' lacks ' . $token;
            }
        }
    }
    assert_true($problems === [], $key . ' exists in all locales with its placeholders'
        . ($problems ? ' (' . implode(', ', $problems) . ')' : ''));
}

// ── [4] the verdict labels stay short enough to sit in a row badge ──────────
foreach (['status.ok', 'status.check', 'status.discard'] as $key) {
    $long = [];
    foreach ($locales as $loc) {
        $value = $strings[$loc][$key] ?? '';
        if ($value === '' || mb_strlen($value) > 14) {
            $long[] = $loc . '="' . $value . '"';
        }
    }
    assert_true($long === [], $key . ' is a short label in every locale' . ($long ? ' (' . implode(', ', $long) . ')' : ''));
}

if ($fail === 0) {
    echo "\nAll i18n icon tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
