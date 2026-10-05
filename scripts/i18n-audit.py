#!/usr/bin/env python3
"""i18n audit for EverShelf.

Reports, for every locale:
  * keys USED in the code (t('...') + data-i18n* attributes) but MISSING in the file
  * translation keys DEFINED but never used
  * suspicious hard-coded Italian strings in JS/HTML (heuristic)
  * HTML attributes (title/placeholder/aria-label) that users can read but that
    are not wired to a data-i18n* attribute — see A1 in todo/AUDIT-2026-10-04-B.md
  * manifest.json icons a browser cannot use: missing file, wrong MIME type,
    declared `sizes` that do not match the real PNG pixels, no `lang`, or no
    192/512 pair for both the `any` and the `maskable` purpose
    — see A2 in todo/AUDIT-2026-10-04-B.md

Usage: python3 scripts/i18n-audit.py [--json]
"""
from __future__ import annotations

import json
import re
import struct
import sys
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TRANS = ROOT / 'translations'
MANIFEST = ROOT / 'manifest.json'
SRC_JS = [ROOT / 'assets/js/app.js', *sorted((ROOT / 'assets/js/core').glob('*.js'))]
SRC_HTML = [ROOT / 'index.html']
# PHP answers are rendered server-side (the ICS expiry feed, future notifications)
# and use evershelfTr('key', $lang) — those keys are used, just not in JS.
SRC_PHP = [ROOT / 'api/index.php', *sorted((ROOT / 'api/lib').glob('*.php'))]
LOCALES = ['it', 'en', 'de', 'fr', 'es', 'zh']

# Attributes a user can read, mapped to the data-i18n* attribute that
# translatePage() applies at runtime (assets/js/app.js).
TRANSLATABLE_ATTRS = {
    'title': 'data-i18n-title',
    'placeholder': 'data-i18n-placeholder',
    'aria-label': 'data-i18n-aria',
}

# Attribute values that stay identical in every locale because they are
# technical examples rather than prose (key/token shapes, URLs, entity ids,
# payload field names, opaque masks). They must NOT be wired to a key, and
# adding a new value here is a deliberate decision: anything else must either
# carry a data-i18n* attribute or live in translations/*.json.
NEUTRAL_VALUES = frozenset({
    # Provider key / token shapes
    'AIza...',                                     # Google API key
    'sk-…',                                        # OpenAI API key
    'eyJhbGci...',                                 # JWT / bearer token
    'GOCSPX-…',                                    # Google OAuth client secret
    '1ABCdef_xyz…',                                # Google OAuth client id
    '1234567890-abc….apps.googleusercontent.com',  # Google OAuth client id (full)
    'X-API-Key',                                   # custom auth header name
    'Authorization',                               # HTTP auth header name
    'ever-shelf,warning',                          # ntfy tag shortcodes (map to emoji)
    # Endpoint examples
    'https://...',
    'http://127.0.0.1:9925',                       # local Mealie
    'https://ntfy.sh',                             # public ntfy server (same in every language)
    'http://n8n.local:5678/webhook/evershelf',      # local n8n webhook (illustrative URL)
    # Model names
    'gpt-4o-mini',
    'llama3.2',
    # Opaque / structural placeholders
    '••••••••',                                    # masked secret
    '...',
    'message',                                     # JSON payload field name
    'evershelf_events',                            # Home Assistant webhook id
})


class _AttributeCollector(HTMLParser):
    """Collect start tags with their attributes, tracking source line numbers."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=False)
        self.tags: list[tuple[int, str, dict[str, str]]] = []

    def handle_starttag(self, tag: str, attrs) -> None:
        self.tags.append((self.getpos()[0], tag, {k: (v or '') for k, v in attrs}))


def untranslated_attributes(html: Path = SRC_HTML[0]) -> list[tuple[int, str, str, str]]:
    """Attributes with user-readable text that no data-i18n* attribute overrides.

    Returns (line, tag, attribute, value) tuples, empty when every attribute is
    either wired to a key or explicitly language-neutral.
    """
    parser = _AttributeCollector()
    parser.feed(html.read_text(encoding='utf-8'))
    found = []
    for line, tag, attrs in parser.tags:
        for attr, marker in TRANSLATABLE_ATTRS.items():
            value = attrs.get(attr, '').strip()
            if value and marker not in attrs and value not in NEUTRAL_VALUES:
                found.append((line, tag, attr, value))
    return found


def png_size(path: Path) -> tuple[int, int] | None:
    """(width, height) read from the PNG IHDR chunk — no Pillow dependency."""
    try:
        with path.open('rb') as fh:
            head = fh.read(24)
    except OSError:
        return None
    if len(head) < 24 or head[:8] != b'\x89PNG\r\n\x1a\n' or head[12:16] != b'IHDR':
        return None
    return struct.unpack('>II', head[16:24])


def manifest_issues(path: Path = MANIFEST) -> list[str]:
    """Icons a browser cannot use, so the install prompt is degraded or absent.

    A `purpose: maskable` icon must be a dedicated file: Android crops it to the
    launcher shape, so reusing the transparent `any` artwork clips the logo.
    Returns human-readable problems, empty when every icon is usable.
    """
    try:
        manifest = json.loads(path.read_text(encoding='utf-8'))
    except (OSError, json.JSONDecodeError) as exc:
        return [f'{path.name}: unreadable ({exc})']

    issues: list[str] = []
    if not manifest.get('lang'):
        issues.append(f'{path.name}: no "lang" — the install prompt cannot pick a locale')

    icons = manifest.get('icons') or []
    if not icons:
        issues.append(f'{path.name}: no "icons" — the app is not installable')

    covered: set[tuple[int, int, str]] = set()
    for icon in icons:
        src = icon.get('src') or '(missing src)'
        declared = icon.get('sizes')
        purpose = (icon.get('purpose') or 'any').lower()
        kinds = {k for k in ('any', 'maskable') if k in purpose} or {'any'}
        file = ROOT / src
        if not file.is_file():
            issues.append(f'{src}: file not found')
            continue
        ext = file.suffix.lower().lstrip('.')
        subtype = (icon.get('type') or '').split('/')[-1].lower()
        if subtype and not (subtype == ext or (ext == 'jpg' and subtype == 'jpeg')):
            issues.append(f'{src}: type "{icon.get("type")}" does not match a .{ext} file')
        real = png_size(file) if ext == 'png' else None
        if real is None:
            issues.append(f'{src}: not a readable PNG, "sizes" cannot be verified')
            continue
        w, h = real
        if declared != f'{w}x{h}':
            issues.append(f'{src}: declares sizes "{declared}" but the file is {w}x{h}px')
        covered.update((w, h, kind) for kind in kinds)

    for size in (192, 512):
        for kind in ('any', 'maskable'):
            if (size, size, kind) not in covered:
                issues.append(
                    f'no {size}x{size} "{kind}" icon — Chromium/Android install prompts '
                    'want both the plain and the maskable artwork at 192 and 512'
                )
    return issues


def flatten(obj, prefix=''):
    out = {}
    for k, v in obj.items():
        key = f'{prefix}.{k}' if prefix else k
        if isinstance(v, dict):
            out.update(flatten(v, key))
        else:
            out[key] = v
    return out


def used_keys() -> set[str]:
    keys: set[str] = set()
    t_re = re.compile(r"""\bt\(\s*(['"])((?:\\.|(?!\1).)*)\1""")
    attr_re = re.compile(r"""data-i18n(?:-[a-z]+)?\s*=\s*(['"])((?:\\.|(?!\1).)*)\1""")
    php_re = re.compile(r"""\bevershelfTr\(\s*(['"])((?:\\.|(?!\1).)*)\1""")
    for f in [*SRC_JS, *SRC_HTML]:
        text = f.read_text(encoding='utf-8')
        for m in t_re.finditer(text):
            keys.add(m.group(2))
        for m in attr_re.finditer(text):
            keys.add(m.group(2))
    for f in SRC_PHP:
        for m in php_re.finditer(f.read_text(encoding='utf-8')):
            keys.add(m.group(2))
    # Drop dynamic prefixes (t('foo.' + x)) and interpolation artefacts.
    return {k for k in keys
            if k and not any(ch in k for ch in " (')+`$")
            and not k.endswith(('.', '_'))}


def main() -> int:
    data = {loc: flatten(json.loads((TRANS / f'{loc}.json').read_text(encoding='utf-8')))
            for loc in LOCALES}
    used = used_keys()
    it = set(data['it'])
    exit_code = 0

    print(f'Reference (it) keys: {len(it)}   Used-in-code keys: {len(used)}')

    missing_in_code = sorted(it - used)
    print(f'\n[1] Defined but unused ({len(missing_in_code)}):')
    for k in missing_in_code[:80]:
        print('    -', k)

    print('\n[2] Used but MISSING per locale:')
    for loc in LOCALES:
        miss = sorted(used - set(data[loc]))
        if miss:
            exit_code = 1
            print(f'  {loc}: {len(miss)} missing')
            for k in miss[:120]:
                print('      *', k)
        else:
            print(f'  {loc}: complete')

    untranslated = untranslated_attributes()
    print(f'\n[3] HTML attributes without a data-i18n* override ({len(untranslated)}):')
    if not untranslated:
        print('    none — every title/placeholder/aria-label is wired or language-neutral')
    else:
        exit_code = 1
        for line, tag, attr, value in untranslated:
            print(f'    index.html:{line} <{tag} {attr}="{value}">')
        print('    -> wire it with data-i18n-<attr> (see TRANSLATABLE_ATTRS), or add its')
        print('       value to NEUTRAL_VALUES when it is a technical example, not prose.')

    icon_problems = manifest_issues()
    print(f'\n[4] manifest.json icons a browser cannot use ({len(icon_problems)}):')
    if not icon_problems:
        print('    none — every icon exists, declares its real size and covers any+maskable')
    else:
        exit_code = 1
        for problem in icon_problems:
            print('    -', problem)

    if '--json' in sys.argv:
        print(json.dumps({
            'missing': {loc: sorted(used - set(data[loc])) for loc in LOCALES},
            'untranslated_attributes': [
                {'file': SRC_HTML[0].name, 'line': line, 'tag': tag,
                 'attribute': attr, 'value': value}
                for line, tag, attr, value in untranslated
            ],
            'manifest_icons': icon_problems,
        }, indent=2))
    return exit_code


if __name__ == '__main__':
    raise SystemExit(main())
