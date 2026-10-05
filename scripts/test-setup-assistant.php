#!/usr/bin/env php
<?php
/**
 * Regression tests: the Settings checklist and the guided assistant must stay
 * wired to the tabs, the wizard steps and the translations.
 *
 * Regression history: new integrations only ever appeared inside the settings page,
 * so the only way to discover one was to open the right tab by accident — the ntfy
 * channel and the cron watchdog both shipped that way, and a user could go months
 * without a single notification, without knowing the option existed. The assistant
 * now asks about every unconfigured option once (SETTINGS_CHECKLIST + askVersion)
 * and the checklist card keeps showing what is still missing.
 *
 * A registry entry is only useful while
 *   * its tab exists (otherwise "Configure" jumps nowhere),
 *   * its title/hint keys exist in all six locales (otherwise the row shows a key),
 *   * an `ask` entry names a wizard step that exists in _setupSteps(),
 *   * the wizard has no hardcoded last-step index (the step list grew).
 *
 * Run: php scripts/test-setup-assistant.php
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

function assert_same($expected, $actual, string $msg): void
{
    global $fail;
    if ($expected !== $actual) {
        echo 'FAIL: ' . $msg . ' (got ' . var_export($actual, true) . ', expected ' . var_export($expected, true) . ")\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

/** First capture group of $re in $s, or '' when it does not match. */
function rx(string $re, string $s): string
{
    return preg_match($re, $s, $m) ? (string)$m[1] : '';
}

/** Nested translation lookup: settings.notify.title → $json['settings']['notify']['title']. */
function tkey(array $json, string $key): ?string
{
    $node = $json;
    foreach (explode('.', $key) as $step) {
        $node = is_array($node) ? ($node[$step] ?? null) : null;
    }
    return is_string($node) && trim($node) !== '' ? $node : null;
}

$root   = __DIR__ . '/..';
$html   = (string)file_get_contents($root . '/index.html');
$appJs  = (string)file_get_contents($root . '/assets/js/app.js');
$api    = (string)file_get_contents($root . '/api/index.php');
$locales = ['it', 'en', 'de', 'fr', 'es', 'zh'];
$json = [];
foreach ($locales as $loc) {
    $json[$loc] = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
}

// ── The card exists and is wired to the renderer ────────────────────────────
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($doc);
assert_same(1, $xp->query("//*[@id='settings-checklist']")->length, 'index.html has the checklist card');
foreach (['checklist-summary', 'checklist-items', 'checklist-toggle'] as $id) {
    assert_same(1, $xp->query("//*[@id='settings-checklist']//*[@id=\"{$id}\"]")->length, "#{$id} lives inside the checklist card");
}
assert_true(str_contains($html, 'onclick="startSetupAssistant()"'), 'the card has the "review the options" button');
assert_true(str_contains($html, 'onclick="_toggleChecklistItems()"'), 'the card can show/hide its rows');
assert_true(str_contains($appJs, 'function _renderSettingsChecklist()'), '_renderSettingsChecklist() exists');
assert_true(str_contains($appJs, "_renderSettingsChecklist();\n    _restoreSettingsNav();"), 'loadSettingsUI() renders the checklist');

// ── Registry: every entry is complete and points at something real ──────────
$body = rx('/const SETTINGS_CHECKLIST = \[(.*?)\n\];/s', $appJs);
assert_true($body !== '', 'SETTINGS_CHECKLIST is declared in app.js');
preg_match_all("/\n    \{\n(.*?)\n    \},/s", $body, $blocks);
$items = [];
foreach ($blocks[1] as $chunk) {
    $items[] = [
        'id'         => rx("/id: '([a-z_]+)'/", $chunk),
        'tab'        => rx("/tab: '(tab-[a-z_]+)'/", $chunk),
        'level'      => rx("/level: '([a-z]+)'/", $chunk),
        'titleKey'   => rx("/titleKey: '([^']+)'/", $chunk),
        'hintKey'    => rx("/hintKey: '([^']+)'/", $chunk),
        'ask'        => str_contains($chunk, 'ask: true'),
        'askVersion' => (int)rx('/askVersion: (\d+)/', $chunk),
        'step'       => rx('/step: (\d+)/', $chunk),
    ];
}
assert_true(count($items) >= 6, 'the registry lists the configurable options (found ' . count($items) . ')');
$ids = array_values(array_filter(array_column($items, 'id')));
assert_same(count($ids), count(array_unique($ids)), 'no duplicate checklist id');

// Wizard step count: every step object starts with a 12-space indented `title:`.
$stepsBody = rx('/function _setupSteps\(\) \{\n    return \[(.*?)\n    \];\n\}/s', $appJs);
$stepCount = $stepsBody === '' ? 0 : preg_match_all("/\n            title: /", $stepsBody);
assert_true($stepCount >= 6, 'the wizard declares its steps, notifications included (found ' . $stepCount . ')');

foreach ($items as $it) {
    $label = $it['id'] !== '' ? $it['id'] : '(entry without id)';
    assert_true($it['id'] !== '', 'every entry declares an id');
    // A checklist row that jumps nowhere is worse than no row at all.
    $onTab    = $xp->query("//button[@data-tab='{$it['tab']}']")->length;
    $hasPanel = $xp->query("//*[@id='{$it['tab']}']")->length;
    assert_true($it['tab'] !== '' && $onTab > 0 && $hasPanel > 0, "{$label}: tab {$it['tab']} exists in the settings page");
    assert_true(in_array($it['level'], ['recommended', 'optional'], true), "{$label}: level is recommended or optional");
    assert_true($it['titleKey'] !== '' && $it['hintKey'] !== '', "{$label}: entry names a title and a hint key");
    foreach ($locales as $loc) {
        assert_true(tkey($json[$loc], $it['titleKey']) !== null, "{$label}: title key {$it['titleKey']} exists in {$loc}");
        assert_true(tkey($json[$loc], $it['hintKey']) !== null, "{$label}: hint key {$it['hintKey']} exists in {$loc}");
    }
    if ($it['ask']) {
        assert_true($it['askVersion'] >= 1, "{$label}: an asked option carries an askVersion to re-ask after a change");
        assert_true($it['step'] !== '', "{$label}: an asked option names its wizard step");
        $step = (int)$it['step'];
        assert_true($step >= 0 && $step < $stepCount, "{$label}: wizard step {$step} exists in _setupSteps() ({$stepCount} steps)");
        assert_true($step !== $stepCount - 1, "{$label}: an asked option never reuses the closing \"done\" step");
    }
}

assert_true(str_contains($appJs, 'function startSetupAssistant()') && str_contains($appJs, 'showSetupWizard(steps)'), 'the button opens the assistant');


// ── The assistant is asked once, and the step list stays dynamic ────────────
assert_true(str_contains($appJs, 'function _assistantPendingSteps()'), '_assistantPendingSteps() exists');
assert_true(str_contains($appJs, 'if ((seen[item.id] || 0) >= (item.askVersion || 1)) continue;'), 'an already asked option is not asked again');
assert_true(str_contains($appJs, 'return missing.concat(_assistantPendingSteps());'), 'the first-run steps include the assistant steps (so it works after the first run too)');
assert_true(str_contains($appJs, 'function _setupDoneStep()'), 'the closing step index is computed, not hardcoded');
assert_true(str_contains($appJs, '_setupPendingSteps.push(_setupDoneStep());'), 'the closing step is appended dynamically');
assert_true(!str_contains($appJs, '_setupPendingSteps.push(4);'), 'the old hardcoded "done" index is gone');
assert_true(str_contains($appJs, 'function _markSetupSeen(ids)'), '_markSetupSeen() exists');
assert_true(str_contains($appJs, '_markSetupSeen([item.id]);'), 'skipping an assistant step records it as asked');
assert_true(str_contains($appJs, "localStorage.getItem('evershelf_setup_seen')"), 'the ledger survives a reload');
assert_true(str_contains($appJs, 'function _setupGenerateTopic()') && str_contains($appJs, 'function _randomNtfyTopic()'), 'the wizard can mint a topic (same generator as the panel)');
assert_true(str_contains($appJs, "api('notify_test'") && str_contains($appJs, "api('notify_healthcheck_test'"), 'both assistant steps can prove themselves before saving');
assert_true(str_contains($appJs, '_serverSettings = serverSettings || {};'), '_initApp stores the payload the checklist reads before choosing the steps');
assert_true(str_contains($appJs, 'function _setupTestNotify()') && str_contains($appJs, 'function _setupTestHealthcheck()'), 'the assistant test buttons exist');

// The calendar row reads ics_enabled: without it in get_settings the row would
// report "not configured" for ever, whatever the real state is.
assert_true(str_contains($api, "'ics_enabled' => env('ICS_ENABLED'"), 'get_settings exposes ics_enabled for the calendar checklist row');

// ── i18n: the keys the renderer emits exist in every locale ─────────────────
$assistantKeys = [
    'settings.checklist.title', 'settings.checklist.summary_ok', 'settings.checklist.summary_todo',
    'settings.checklist.state_ok', 'settings.checklist.state_todo', 'settings.checklist.state_optional',
    'settings.checklist.configure', 'settings.checklist.show', 'settings.checklist.hide',
    'settings.checklist.run', 'setup.notify_test_btn', 'setup.notify_need_topic',
];
foreach ($locales as $loc) {
    foreach ($assistantKeys as $key) {
        assert_true(tkey($json[$loc], $key) !== null, "translations/{$loc}.json has {$key}");
    }
    // summary_todo is a template: it must keep its {count} slot.
    assert_true(str_contains((string)tkey($json[$loc], 'settings.checklist.summary_todo'), '{count}'),
        "translations/{$loc}.json keeps {count} in settings.checklist.summary_todo");
}

echo $fail === 0 ? "\nAll setup-assistant tests passed.\n" : "\n{$fail} test(s) FAILED.\n";
exit($fail === 0 ? 0 : 1);
