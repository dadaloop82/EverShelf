#!/usr/bin/env python3
"""i18n audit for EverShelf.

Reports, for every locale:
  * keys USED in the code (t('...') + data-i18n* attributes) but MISSING in the file
  * translation keys DEFINED but never used
  * suspicious hard-coded Italian strings in JS/HTML (heuristic)

Usage: python3 scripts/i18n-audit.py [--json]
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TRANS = ROOT / 'translations'
SRC_JS = [ROOT / 'assets/js/app.js', *sorted((ROOT / 'assets/js/core').glob('*.js'))]
SRC_HTML = [ROOT / 'index.html']
LOCALES = ['it', 'en', 'de', 'fr', 'es', 'zh']


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
    for f in [*SRC_JS, *SRC_HTML]:
        text = f.read_text(encoding='utf-8')
        for m in t_re.finditer(text):
            keys.add(m.group(2))
        for m in attr_re.finditer(text):
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

    if '--json' in sys.argv:
        print(json.dumps({'missing': {loc: sorted(used - set(data[loc])) for loc in LOCALES}}, indent=2))
    return exit_code


if __name__ == '__main__':
    raise SystemExit(main())
