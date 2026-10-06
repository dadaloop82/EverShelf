#!/usr/bin/env php
<?php
/**
 * Regression tests: a scanned product can be corrected before it enters the pantry.
 *
 * A barcode label or an AI guess is often wrong, and the add-to-pantry step is the
 * last moment the user sees it. The identified TITLE and the brand are tappable
 * there (and on the AI match card), and the correction must survive the next rescan
 * of the same barcode — otherwise the app keeps fighting the user:
 *
 *   app.js              one renderer owns the add preview (_renderAddProductPreview()),
 *                       showAddForm() snapshots what the catalog holds and paints it,
 *   submitAdd()         persists the correction through _commitAddProductRename(),
 *                       which sends `name_user_set: 1` — the flag mergeIncomingProductFields()
 *                       honours by keeping the typed name verbatim — and reads the
 *                       stored title back through product_get, because the backend
 *                       still owns the spelling (genre prefix, capital, singular),
 *   style.css           the second tap target is styled, not an invisible hitbox,
 *   translations/*      every label the new fields print exists in all six locales.
 *
 * Run: php scripts/test-product-rename.php
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
$js   = (string)file_get_contents($root . '/assets/js/app.js');
$css  = (string)file_get_contents($root . '/assets/css/style.css');

/** Window that starts at the first $needle (empty when the anchor is gone). */
function slice_from(string $source, string $needle, int $len = 4000): string
{
    $pos = strpos($source, $needle);
    return $pos === false ? '' : substr($source, $pos, $len);
}

/** True when the dotted key exists and is a non-empty string in that locale. */
function i18n_has(string $locale, string $dotted): bool
{
    $path = __DIR__ . '/../translations/' . $locale . '.json';
    $data = json_decode((string)file_get_contents($path), true);
    $cur  = $data;
    foreach (explode('.', $dotted) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return false;
        }
        $cur = $cur[$part];
    }
    return is_string($cur) && trim($cur) !== '';
}

// ── one renderer paints the add preview, and it snapshots what the catalog holds ──
$addForm = slice_from($js, 'function showAddForm() {', 400);
assert_true($addForm !== '', 'app.js: showAddForm() still exists');
assert_true(str_contains($addForm, '_snapshotCatalogTitle();'),
    'app.js: showAddForm() snapshots the stored title/brand before painting them');
assert_true(str_contains($addForm, '_renderAddProductPreview();'),
    'app.js: showAddForm() paints the preview through its renderer');
assert_true(!str_contains($js, "document.getElementById('add-product-preview').innerHTML"),
    'app.js: no second, read-only renderer of #add-product-preview survived');

$preview = slice_from($js, 'function _renderAddProductPreview() {', 2200);
assert_true($preview !== '', 'app.js: _renderAddProductPreview() exists');
assert_true(str_contains($preview, "document.getElementById('add-product-preview')"),
    'app.js: the renderer owns the #add-product-preview host');
foreach (['add-title-display', 'add-product-name', 'add-brand-display', 'add-product-brand'] as $id) {
    assert_true(str_contains($preview, "id=\"{$id}\""),
        "app.js: the preview renders the editable field '{$id}'");
}
assert_true(substr_count($preview, '_wireInlineTitleEdit(') === 2,
    'app.js: both preview fields are wired for keyboard/blur commit');
assert_true(str_contains($preview, "escapeHtml(t('product.brand_label')"),
    'app.js: an empty brand line shows its translated label, not a literal');

// ── the shared inline editor: Enter commits, Escape restores, blur commits ────────
$wire = slice_from($js, 'function _wireInlineTitleEdit(', 600);
assert_true(str_contains($wire, "ev.key === 'Enter'"), 'app.js: Enter commits the edit');
assert_true(str_contains($wire, "ev.key === 'Escape'"), 'app.js: Escape restores the previous value');
assert_true(str_contains($wire, "addEventListener('blur'"), 'app.js: leaving the field commits it');
assert_true(str_contains($js, "if (!input || input.style.display === 'none') return;"),
    'app.js: a finished edit is a no-op, so Escape-then-blur cannot re-commit');

// ── the correction is persisted on the product, locked as the user's name ─────────
$submit = slice_from($js, 'async function submitAdd(e) {', 4000);
assert_true(str_contains($submit, 'if (_addProductTitleChanged()) {'),
    'app.js: submitAdd() only renames when the user actually changed something');
assert_true(str_contains($submit, 'await _commitAddProductRename();'),
    'app.js: submitAdd() persists the corrected title/brand before adding to the pantry');

$commit = slice_from($js, 'async function _commitAddProductRename()', 1800);
assert_true($commit !== '', 'app.js: _commitAddProductRename() exists');
assert_true(str_contains($commit, "api('product_save'"), 'app.js: the rename is saved through product_save');
assert_true(str_contains($commit, 'name_user_set: 1'),
    'app.js: the rename is flagged name_user_set — a rescan cannot undo it');
assert_true(str_contains($commit, "api('product_get'"),
    'app.js: the stored title is read back (the backend owns the spelling)');
assert_true(str_contains($commit, '_renderAddProductPreview();'),
    'app.js: the preview is repainted with the stored title');
assert_true(str_contains($commit, '_savedTitle'),
    'app.js: the snapshot is refreshed, so a second submit does not rename again');

// ── the AI identification card can be corrected before it is saved ───────────────
foreach (['scan-ai-title-display', 'scan-ai-product-name', 'scan-ai-brand-display', 'scan-ai-product-brand'] as $id) {
    assert_true(str_contains($js, "'{$id}'"),
        "app.js: the AI match card exposes the editable field '{$id}'");
}
assert_true(str_contains($js, "_wireInlineTitleEdit('scan-ai-product-name', finishEditScanAiTitle)"),
    'app.js: the AI card title is wired to its commit handler');
assert_true(str_contains($js, "_wireInlineTitleEdit('scan-ai-product-brand', finishEditScanAiBrand)"),
    'app.js: the AI card brand is wired to its commit handler');
assert_true(str_contains(slice_from($js, 'function finishEditScanAiTitle(', 900), '_showAiMatchChoices(_aiDetectedProductDraft)'),
    'app.js: an edited AI draft repaints the card (hero text + add-button label)');
$confirm = slice_from($js, 'async function _confirmAiDetectedProduct() {', 1200);
assert_true(str_contains($confirm, 'name: aiName') && str_contains($confirm, 'brand: p.brand'),
    'app.js: the confirmed AI draft saves the edited name and brand');

// ── the second tap target is styled, not an invisible hitbox ─────────────────────
foreach (['.edit-subtitle-tap {', '.edit-subtitle-empty {', '.edit-brand-input {'] as $rule) {
    assert_true(str_contains($css, $rule), "style.css: '{$rule}' is declared");
}

// ── every label the new fields print exists in all six locales ───────────────────
$keys = ['product.edit_name_brand', 'edit.label_name', 'product.brand_label',
         'product.brand_placeholder', 'product.name_required', 'toast.product_saved', 'error.save'];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    foreach ($keys as $key) {
        assert_true(i18n_has($loc, $key), "{$loc}: '{$key}' is translated");
    }
}
assert_true(substr_count($js, "t('product.edit_name_brand')") >= 4,
    'app.js: both tap targets (preview + AI card) carry the translated tooltip');

// ── backend contract: `name_user_set` is what makes the correction stick ─────────
// The client sends the flag; the merge choke point is what honours it, so the
// guarantee is asserted against the real code (pure function, no request needed).
define('CRON_MODE', true);
require_once $root . '/api/bootstrap.php';
require_once $root . '/api/index.php';

$existing = [
    'id' => 1,
    'name' => 'Yogurt Fiori di latte',
    'brand' => 'Granarolo',
    'category' => 'latticini',
    'unit' => 'conf',
    'default_quantity' => 125,
    'package_unit' => 'g',
    'notes' => '',
    'image_url' => '',
    'barcode' => '8051234567890',
    'kind' => 'Yogurt',
    'shopping_name' => 'Yogurt',
    'nutriments_json' => null,
    'name_user_set' => 0,
];
$renamed = mergeIncomingProductFields($existing, [
    'name' => 'yogurt greco',
    'brand' => 'Fage',
    'category' => 'latticini',
    'unit' => 'conf',
    'default_quantity' => 125,
    'package_unit' => 'g',
    'lang' => 'it',
    'name_user_set' => 1,
], null);
assert_true($renamed['name'] === 'Yogurt greco',
    'api: the typed title is kept (and still spelled by the app: capital letter)');
assert_true($renamed['brand'] === 'Fage', 'api: the corrected brand is kept');
assert_true((int)$renamed['name_user_set'] === 1, 'api: the correction is stored as the user name');

$stored = array_merge($existing, [
    'name' => $renamed['name'],
    'brand' => $renamed['brand'],
    'name_user_set' => 1,
]);
$rescanned = mergeIncomingProductFields($stored, [
    'name' => 'FIORI DI LATTE',
    'brand' => 'Granarolo',
    'category' => 'latticini',
    'unit' => 'conf',
    'default_quantity' => 125,
], '8051234567890');
assert_true($rescanned['name'] === 'Yogurt greco',
    'api: rescanning the same barcode does not bring the wrong title back');
assert_true($rescanned['brand'] === 'Fage',
    'api: rescanning the same barcode does not bring the wrong brand back');

if ($fail === 0) {
    echo "\nAll product-rename tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
