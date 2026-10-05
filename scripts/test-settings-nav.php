#!/usr/bin/env php
<?php
/**
 * Regression tests: the settings page navigation (sections → tabs) must stay
 * consistent between the three places that describe it.
 *
 * Regression history: the settings page opened with 16 tab buttons in a single
 * scrolling strip and mixed unrelated panels (Home Assistant next to the
 * shopping list). Splitting the strip in sections only works while
 *   * every tab declares a `data-group` that app.js knows (SETTINGS_GROUPS),
 *   * every tab has a panel to open, every panel a tab to reach it,
 *   * each section's label key exists in all six locales,
 *   * the page opens on a tab that belongs to the section highlighted as active.
 * A tab left behind in another section is invisible (display:none) but still
 * clickable by the keyboard, so a mismatch makes a settings card unreachable
 * without any error.
 *
 * It also guards the second half of the same fix: the Kiosk banner, the Kiosk
 * panels and the About card used to live *outside* .settings-panels, so they
 * were painted below every single tab; they must live inside the Info panel.
 *
 * Run: php scripts/test-settings-nav.php
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

$root  = __DIR__ . '/..';
$html  = (string)file_get_contents($root . '/index.html');
$appJs = (string)file_get_contents($root . '/assets/js/app.js');

// ── The four sections, as declared in app.js ────────────────────────────────
$declared = [];
if (preg_match('/const SETTINGS_GROUPS = \[([^\]]*)\];/', $appJs, $m)) {
    $declared = array_values(array_filter(array_map(
        static fn ($p) => trim($p, " \t\n\r'\""),
        explode(',', $m[1])
    ), static fn ($p) => $p !== ''));
}
assert_true($declared !== [], 'app.js declares SETTINGS_GROUPS');

// ── Section buttons + their i18n keys ───────────────────────────────────────
preg_match_all(
    '/<button class="settings-group-btn[^"]*"[^>]*data-group="([a-z_]+)"[^>]*data-i18n="(settings\.[a-z_]+)"/',
    $html,
    $groupMatches,
    PREG_SET_ORDER
);
$groupButtons = [];
foreach ($groupMatches as $g) {
    $groupButtons[$g[1]] = $g[2];
}
assert_same($declared, array_keys($groupButtons), 'every section button declares a known group, in the SETTINGS_GROUPS order');

// ── Tabs: group + panel, both directions ────────────────────────────────────
preg_match_all('/<button class="settings-tab[^"]*"[^>]*?data-group="([a-z_]+)" data-tab="(tab-[a-z_]+)"/', $html, $tabMatches, PREG_SET_ORDER);
$tabs = [];
foreach ($tabMatches as $t) {
    $tabs[$t[2]] = $t[1];
}
assert_true(count($tabs) >= 10, 'index.html declares the settings tabs with their section (found ' . count($tabs) . ')');

$unknown = array_values(array_unique(array_diff(array_values($tabs), $declared)));
assert_same([], $unknown, 'no tab points at a section app.js does not know');

foreach ($declared as $group) {
    $n = count(array_filter($tabs, static fn ($g) => $g === $group));
    assert_true($n > 0, "section '{$group}' has at least one tab (an empty section is a dead button)");
}

// Every tab needs a panel, and every panel needs exactly one tab: a renamed id
// would otherwise hide a whole settings card with no error anywhere.
preg_match_all('/<div class="settings-panel[^"]*" id="(tab-[a-z_]+)">/', $html, $panelMatches);
$panels = $panelMatches[1];
assert_same([], array_values(array_diff($panels, array_keys($tabs))), 'no settings panel is unreachable from the tab strip');
assert_same([], array_values(array_diff(array_keys($tabs), $panels)), 'no tab opens a panel that does not exist');
assert_same(count(array_unique($panels)), count($panels), 'no duplicate settings panel id');

// ── The page must open on a tab of the highlighted section ──────────────────

// ── i18n: every section label exists in all six locales ─────────────────────
$labels = array_values($groupButtons);
$labels[] = 'settings.groups_aria';
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $json = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $missing = [];
    foreach ($labels as $key) {
        $node = $json;
        foreach (explode('.', $key) as $step) {
            $node = is_array($node) ? ($node[$step] ?? null) : null;
        }
        if (!is_string($node) || trim($node) === '') {
            $missing[] = $key;
        }
    }
    assert_same([], $missing, "translations/{$loc}.json has every settings section label");
}

// ── The wiring in app.js: switching a tab brings its section forward ────────
$switchFn = '';
$pos = strpos($appJs, 'function switchSettingsTab(btn, tabId)');
if ($pos !== false) {
    $switchFn = substr($appJs, $pos, 900);
}
assert_true(str_contains($switchFn, '_syncSettingsGroupForTab(tabId)'), 'switchSettingsTab() syncs the section strip');
assert_true(str_contains($appJs, 'function switchSettingsGroup(group, keepTab = false)'), 'switchSettingsGroup() exists');
assert_true(str_contains($appJs, 'function _syncSettingsGroupForTab(tabId)'), '_syncSettingsGroupForTab() exists');
assert_true(str_contains($appJs, 'function _restoreSettingsNav()'), '_restoreSettingsNav() exists');
assert_true(str_contains($appJs, "localStorage.setItem('evershelf_settings_tab', tabId)"), 'the last opened tab is remembered');
$loadFn = '';
$pos = strpos($appJs, 'async function loadSettingsUI()');
if ($pos !== false) {
    $end = strpos($appJs, '_restoreSettingsNav();', $pos);
    $loadFn = $end === false ? '' : substr($appJs, $pos, $end - $pos + 25);
}
assert_true(str_contains($loadFn, '_restoreSettingsNav()'), 'loadSettingsUI() reopens the remembered section and tab');
assert_true(str_contains($appJs, "classList.toggle('settings-tab-hidden'"), 'inactive sections hide their tabs');
assert_true(
    str_contains((string)file_get_contents($root . '/assets/css/style.css'), '.settings-tab.settings-tab-hidden { display: none; }'),
    'the hidden class really hides the tab'
);

// ── Kiosk + About must not be painted below every tab ──────────────────────
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($doc);
foreach (['kiosk-download-banner', 'kiosk-native-settings-panel', 'kiosk-update-panel', 'about-version-label'] as $id) {
    $node = $xp->query("//*[@id=\"{$id}\"]");
    assert_same(1, $node->length, "index.html has exactly one #{$id}");
    if ($node->length === 1) {
        assert_same(1, $xp->query("//*[@id='tab-info']//*[@id=\"{$id}\"]")->length, "#{$id} lives inside the Info panel (not below every tab)");
    }
}
assert_same(0, $xp->query("//*[@id='settings-status']/ancestor::*[@id='tab-info']")->length, 'the global save/status bar stays outside the panels');

// ── Every card is a collapsed sub-section ──────────────────────────────────
// Two panels (Notifications, Home Assistant) hold ~ten cards each; with all of
// them open the option you came for is somewhere below the fold. Cards with a
// heading become collapsible sub-sections and only one is open at a time.
assert_true(str_contains($appJs, 'function _initSettingsAccordions()'), '_initSettingsAccordions() exists');
assert_true(str_contains($appJs, '_initSettingsAccordions();'), 'the accordion is initialised with the settings page');
assert_true(str_contains($appJs, "card.dataset.accordion = 'ready';"), 'a card is marked once it has a header');
assert_true(str_contains($appJs, 'function _toggleSettingsCard(card)') && str_contains($appJs, 'function _closeSettingsCards('),
    'a card can be toggled and the others closed');
assert_true(str_contains($appJs, 'function _openSettingsCardFor(el)'), 'a card can be opened programmatically');
assert_true(str_contains($appJs, '_openSettingsCardFor(card);'), 'a checklist jump opens the card it points at');
$css = (string)file_get_contents($root . '/assets/css/style.css');
assert_true(str_contains($css, '.settings-card[data-accordion] > .settings-card-body'), 'the card body is the collapsible part');
assert_true((bool)preg_match('/\.settings-card\[data-accordion\] > \.settings-card-body \{\s*display: none;/s', $css),
    'cards start collapsed');
assert_true(str_contains($css, '.settings-card[data-accordion].open > .settings-card-body'), 'an open card shows its body');
assert_true(str_contains($css, '.settings-card[data-accordion].open > .settings-card-head .settings-card-chevron'),
    'the chevron reports the open state');

// ── Level 2 are sub-sections: wrapped, indented, all visible ────────────────
// The pill strip scrolled horizontally, so the last sub-sections of a section
// were unreachable-looking. They wrap now and carry a label that names the level.
assert_true(str_contains($html, 'settings-subsections-label'), 'index.html labels the second level');
assert_same(1, substr_count($html, 'settings-subsections-label'), 'the label appears once, not per section');
$tabsBlock = '';
if (preg_match('/\.settings-tabs \{(.*?)\}/s', $css, $mTabs)) {
    $tabsBlock = $mTabs[1];
}
assert_true(str_contains($tabsBlock, 'flex-wrap: wrap'), 'the sub-section list wraps instead of scrolling');
assert_true(!str_contains($tabsBlock, 'overflow-x: auto'), 'the sub-section list never scrolls sideways');
assert_true(str_contains($tabsBlock, 'border-left'), 'the sub-sections are visually nested under their section');
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $json = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $label = $json['settings']['subsections_label'] ?? null;
    assert_true(is_string($label) && trim($label) !== '', "translations/{$loc}.json has settings.subsections_label");
}

// ── The remembered section + tab must open together ────────────────────────
preg_match('/<button class="settings-tab ([^"]*)"[^>]*data-group="([a-z_]+)" data-tab="(tab-[a-z_]+)"/', $html, $activeTab);
preg_match('/<button class="settings-group-btn ([^"]*)"[^>]*data-group="([a-z_]+)"/', $html, $activeGroup);
assert_same('active', $activeTab[1] ?? '', 'a tab starts as active');
assert_same('active', $activeGroup[1] ?? '', 'a section starts as active');
assert_same($activeGroup[2] ?? '', $activeTab[2] ?? '', 'the tab open at load belongs to the section marked active');

echo $fail === 0 ? "\nAll settings-navigation tests passed.\n" : "\n{$fail} test(s) FAILED.\n";
exit($fail === 0 ? 0 : 1);
