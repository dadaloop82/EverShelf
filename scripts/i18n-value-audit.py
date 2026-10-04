#!/usr/bin/env python3
"""Value-level i18n audit for EverShelf.

`i18n-audit.py` checks that every *key* exists. It cannot see that a key exists
but still holds the English string, which is how translations silently regress.
This script reports, per locale, the flattened keys whose value is byte-identical
to English, minus an allow-list of tokens that are legitimately identical
(proper nouns, units, symbols).

Usage:
    python3 scripts/i18n-value-audit.py            # report only
    python3 scripts/i18n-value-audit.py --strict   # exit 1 when unallowed gaps exist
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TRANS = ROOT / "translations"
LOCALES = ["it", "en", "de", "fr", "es", "zh"]

# Values that are the same in every language on purpose.
ALLOW = {
    "EverShelf", "Gemini", "WhatsApp", "Home Assistant", "Google Drive",
    "OpenAI", "Llama", "Quagga", "ZBar", "MCP", "API", "OK", "Zoom", "CSV",
    "JSON", "URL", "URL:", "Email", "Email:", "ID", "kg", "g", "mg", "ml",
    "cl", "l", "cm", "mm", "pz", "conf", "min", "MAX", "%", "°C", "€", "$",
    "-", "–", "—", "…", "•", "·",
}


def flatten(obj, prefix=""):
    out = {}
    for k, v in obj.items():
        key = f"{prefix}.{k}" if prefix else k
        if isinstance(v, dict):
            out.update(flatten(v, key))
        else:
            out[key] = v
    return out


def main() -> int:
    en = flatten(json.loads((TRANS / "en.json").read_text(encoding="utf-8")))
    strict = "--strict" in sys.argv
    gaps_total = 0

    for loc in LOCALES:
        if loc == "en":
            continue
        data = flatten(json.loads((TRANS / f"{loc}.json").read_text(encoding="utf-8")))
        same = []
        for key, en_val in en.items():
            if not isinstance(en_val, str) or key not in data:
                continue
            val = data[key]
            if not isinstance(val, str) or val.strip() != en_val.strip():
                continue
            if en_val.strip() in ALLOW or len(en_val.strip()) <= 2:
                continue
            same.append((key, en_val))
        gaps_total += len(same)
        print(f"\n{loc}: {len(same)} value(s) identical to English")
        for key, val in sorted(same)[:40]:
            print(f"    {key} = {val!r}")
        if len(same) > 40:
            print(f"    ... and {len(same) - 40} more")

    print(f"\nTotal identical values: {gaps_total}")
    if strict and gaps_total:
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
