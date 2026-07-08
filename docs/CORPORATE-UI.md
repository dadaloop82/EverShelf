# EverShelf Corporate UI

Design tokens and patterns for a consistent look across the PWA. All new UI should follow these rules.

## Brand palette

| Token | Value | Usage |
|-------|-------|--------|
| `--primary` | `#2d5016` | Header, nav, quantity pills, primary chrome |
| `--primary-light` | `#4a7c28` | Gradients, hover states |
| `--accent` | `#7c3aed` | Recipe / AI actions |
| `--success` | `#16a34a` | Confirm, “used all”, positive stock |
| `--warning` | `#f59e0b` | Use / consume actions |
| `--danger` | `#dc2626` | Discard, errors, expired-critical |

Background: `--bg` (`#f0f4e8`), cards: `--bg-card` (`#ffffff`).

## Typography

- **Font stack:** `--es-font` (system UI fonts only — no custom webfonts).
- **Page titles:** ~1.1–1.25rem, weight 700.
- **Body / meta:** 0.82–0.9rem; muted labels use `--text-muted`.
- **Micro labels (chips, hints):** 0.68–0.75rem.

## Spacing & shape

- **Card radius:** `--es-radius-card` (10px).
- **Page radius:** `--radius` (16px).
- **Spacing scale:** `--es-space-xs` (4) → `--es-space-xl` (24).
- **Touch targets:** minimum `--es-btn-height` (48px); large CTAs `--es-btn-height-lg` (52px).

## Buttons

| Intent | Class / pattern | Color |
|--------|-----------------|-------|
| Use / partial consume | `.btn-warning`, `.item-detail-action--use` | Warning gradient |
| Used all / confirm | `.btn-success`, `.item-detail-action--all` | Success gradient |
| Recipe / AI | `.btn-accent`, `.item-detail-action--recipe` | Accent gradient |
| Discard | `.item-detail-action--throw`, `.btn-danger` | Outline or solid danger |
| Secondary / cancel | `.btn-secondary` | Neutral border |

**Idle auto-submit** (Add form 30s, Use form 15s for pcs/conf): reverse `linear-gradient` wipe on the submit button; hint text below in `--text-muted`.

## Inventory list

- **Nav label:** “List” / “Lista” (not “Pantry” — that name is reserved for the storage *location* tab).
- **Swipe left:** open Use page with quantity + optional 15s countdown.
- **Swipe right:** edit product.
- **Tap:** product detail modal with four actions (Use, Used all, Recipe, Discard).

## Modals

- Header: title left, icon actions right (edit ✏️, close ✕).
- Hero block: image + quantity pill + status chips.
- Meta rows: label left, value right.
- Action grid: 2×2 on mobile, equal height tiles.

## Do / don’t

- **Do** reuse CSS variables; avoid one-off hex colors.
- **Do** keep one font family and two weights (600/700 for emphasis).
- **Don’t** mix unrelated button heights on the same row.
- **Don’t** use Italian copy in Markdown docs (English only).
