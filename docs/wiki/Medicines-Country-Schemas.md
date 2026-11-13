# Medicine label standards, per country (contributor guide)

> **Short version**: how a medicine box is identified is a **national** fact, so it lives in
> `data/medical_code_schemas.json` — one block per country, **data only**. Adding a country is a
> JSON block (and, only when a regex is not enough, one small module). The app picks it up with
> no other change, and it **blocks** the medicine scanner with an explicit dialog in every
> country it does not know yet.

## Why this file exists

EverShelf is a pantry manager that can also keep a medicine cabinet. Recognising a drug box is
not like reading a yoghurt: **every country prints a different number, in a different place, and
the barcodes on the box are often packaging rather than the drug**. Getting this wrong is worse
than not doing it — the app would name the wrong box, or store a serial number as if it were a
medicine.

So the rules are:

| Rule | Where it lives |
|---|---|
| which number identifies the drug | `countries.<CC>.codes` |
| which codes are **packaging** and must be ignored | `countries.<CC>.ignored` |
| how the printed number is read (OCR), and what to frame | `countries.<CC>.ocr` |
| whether the EU FMD DataMatrix applies, and which AI carries the national number | `countries.<CC>.fmd` + `fmd_national_ai` |
| the national catalogue, if it is open data | `countries.<CC>.dataset` |
| a summary the UI reads (can this country be scanned?) | `countries.<CC>.scan` |

Everything the *engine* does with those rules is country-neutral: `api/lib/medical.php` +
`assets/js/core/med.js` (GS1 walker, `codes`/`ignored`/`ocr` lists, module registry). Anything
that cannot be expressed as a pattern — Italy's three printed AIC forms plus AI 710 — lives in a
**country module** (`api/lib/med/italy.php` + `assets/js/med/italy.js`), declared by
`"module": "italy"`.

## What is supported today

| Country | Identifies the drug with | Printed number read by OCR | Catalogue |
|---|---|---|---|
| 🇮🇹 IT | **AIC** — printed (`A` + 9 digits; bare 9 in the old Code 32) **and** carried by a Code 39 of the bollino, plus AI **710** of the FMD DataMatrix. The rest of the sticker is packaging: the lot (`16G87N`), the pack's progressive number with its check digit (`0140581421`), the DataMatrix that joins them | yes — the big `A040313076` block, inside the guide rectangle (and, when the letter is lost, the digit runs, proved against the catalogue) | **AIFA open data**, CSV/XML, `import-medicines.php --country=IT` |
| 🇩🇪 DE | **PZN** (8 digits, printed in clear text *and* as Code 39) + AI **710** | yes — `PZN 00000000` | not open (IFA/ABDATA licence): the scan works, the name is typed |
| 🇺🇸 US | **NDC** printed (`12345-678-90`) + UPC/EAN barcode | yes — `NDC …` | **openFDA NDC Directory**, JSON, public domain, `import-medicines.php ndc.json --country=US` |
| 🇦🇹 AT | EAN-13 with the PZN embedded (`908888…`) + AI 710 | no (the EAN carries it) | — |
| 🇫🇷 FR | **CIP-13 = the EAN-13 itself** + AI 710 | no (the barcode *is* the number) | — |
| 🇪🇸 ES | EAN-13 and Código Nacional (AI **710**) | no (6–7 printed digits are too ambiguous) | — |

## How to add your country

1. Copy the block of a similar country in `data/medical_code_schemas.json` and fill it *from your
   country's official source* (the medicines agency, the national coding body, GS1). Put the URLs
   in the top-level `sources` array — a rule without a source is a guess.
2. `status`: `full` (national code + FMD), `partial` (only the GS1 range and/or FMD), `none`.
3. `codes`: only the numbers that **identify the drug**. Each entry needs a `pattern` matched
   against the *whole* read, letters included (see the traps below).
4. `ignored`: the **packaging** codes that must be thrown away, with `"action": "ocr"` when the
   drug number is printed and can be read from the label.
5. `ocr` — only if the number is really **printed**:

   ```json
   "ocr": { "label_key": "medical.ocr_btn", "hint_key": "medical.scan_hint_xxx",
            "sample": "A000000000", "patterns": ["A[ .:-]?\\d{9}"],
            "frame": { "width_pct": 78, "height_pct": 18 }, "name_search": true }
   ```

   `sample` is what the guide rectangle shows on screen; `patterns` must be specific (the project
   once accepted a bare 9-digit run and read the pack serial instead of the AIC).
6. `dataset` — only when the national catalogue is **open data**: `id`, `url`, `licence`,
   `importer` and `open: true`. If it needs a licence, use `"open": false` + `"note_key"` so the
   UI says why the names cannot be filled.
7. `scan.barcodes` — `true` wherever a barcode can carry the drug number (Italy: the AIC is a
   Code 39 on the bollino; Germany, the US, France). Use `false` ONLY if the pack really has no
   barcode form of the number: the scanner then reads the printed block and nothing else.
8. If a regex is not enough (several printed forms, a national number inside an AI of the GS1
   DataMatrix, a catalogue with its own column names), add a module:

   ```
   api/lib/med/<slug>.php   → function medical<Slug>Module(): array   (local_code, dataset_columns, dataset_id)
   assets/js/med/<slug>.js  → _medRegisterCountryModule('<slug>', { cc, localCode(read) })
   ```

   then `"module": "<slug>"` in the block. The core only asks the module *"is this read one of
   your drug codes?"* — it never learns what an AIC, a PZN or an NDC is.
9. Add the strings you use (`medical.code_*`, `medical.scan_hint_*`, `medical.dataset_*`) to
   **all six locales**, then run:

   ```bash
   python3 scripts/i18n-audit.py     # no missing key in any locale
   ```

## The traps this file already knows about

- **A barcode can BE the drug, and only the catalogue can tell.** Italy prints the AIC as text
  **and** carries it in a Code 39 of the bollino, next to the lot (`16G87N`) and the pack's
  progressive number (`0140581421`) — codes that look identical in shape. So the rule is not
  "read no barcode" but **"accept a read only when the national number resolves and the
  catalogue contains it"** (`_medConfirmRead`): a serial that happens to start with an `A`
  matches no row and is thrown away, exactly like a wrong OCR digit. Italy therefore keeps
  `scan.barcodes: true`; the packaging patterns are listed under `ignored`.
- **A bare digit run is dangerously generic.** Never accept `\d{9}` (or `\d{6,7}`) from OCR on
  its own: the label is full of numbers. The reader may offer the digit runs of a page
  (`_medOcrDigitCandidates`) **only when a catalogue is loaded**, and every candidate must be
  proved by a row before anything opens — the pack's progressive number is in none of them.
- **The DataMatrix is standard, the number inside it is national.** The core parses the GS1
  element string and exposes AI `71x`; the country module decides what it means.
- **No catalogue is bundled.** The names come from a national open-data import, on the household's
  own server (`scripts/import-medicines.php`, CSV/XML/JSON). No medicine API is called by
  default. An OPTIONAL online provider (`MED_LOOKUP_PROVIDER=medifontis`, `MED_LOOKUP_KEY`,
  off unless set) is asked only for a code the local table knows nowhere — and its answer is
  written into the local table, so the next scan works offline. The catalogue stays the
  authority: a provider fills gaps, it does not replace the verification.

## Related

- `docs/wiki/Medicines.md` — the module itself (opt-in, cabinet, symptom lookup, panels).
- `api/lib/medicines.php` — the local catalogue store (key, search, importer).

| 🇬🇧 GB | EAN-13 (no national reimbursement number since Brexit) | no | — |
| 🇨🇭 CH, 🇧🇪 BE, 🇳🇱 NL, 🇵🇹 PT, 🇵🇱 PL, 🇬🇷 GR, 🇸🇪 SE, 🇩🇰 DK, 🇫🇮 FI, 🇮🇪 IE | EAN/GTIN of the country's range (+ FMD where it applies) | no | — |
| anything else | **nothing** | **blocked** | **blocked** |

**The blocked case is a feature, not a gap:** with no schema the app shows a dialog that says
*"for this country we do not have the label schema yet"*, offers the hand entry, and points at
this document. It never scans a label it cannot interpret.
