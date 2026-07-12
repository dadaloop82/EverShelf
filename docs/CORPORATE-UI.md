# EverShelf Corporate UI

Design tokens live in `assets/css/style.css` (`:root`). **App-wide component styles** live in `assets/css/corporate.css`, loaded **after** `style.css` in `index.html`. The refactor shipped in **v1.7.57** (July 2026).

## Architecture

| File | Role |
|------|------|
| `style.css` | Page layout, page-specific rules, dark mode, legacy components, CSS custom properties |
| `corporate.css` | Unified buttons, cards, forms, modals, tabs, lists, navigation, shopping rows |

`corporate.css` is a **layer on top** of `style.css` — it standardizes look-and-feel without replacing routing, i18n, or dark-mode logic.

## Scope (app-wide)

The corporate layer touches every major surface:

| Surface | Examples in `corporate.css` |
|---------|----------------------------|
| **Typography** | System UI font stack (`--es-font`), title weights 700, consistent body/small/micro sizes |
| **Buttons** | `.btn`, `.btn-primary`, `.btn-success`, `.btn-warning`, `.btn-accent`, `.btn-secondary`, `.btn-large` |
| **Cards** | `.stat-card`, `.section-card`, `.settings-card`, `.alert-card`, `.product-item`, `.es-surface` |
| **Tabs** | Location tabs, settings tabs, shopping tabs — pill style with active gradient |
| **Forms** | `.form-input`, `.qty-input`, `.search-bar`, `.loc-btn`, `.qty-control`, chips and fraction buttons |
| **Inventory list** | `.inventory-item`, swipe backgrounds, category headers, badges |
| **Shopping** | `.shop-row` cards with unified border/shadow |
| **Modals** | Blurred overlay, top accent border, `.item-detail-*` product sheet, `.modal-detail` rows |
| **Chrome** | `.app-header`, `.page-header`, back/action buttons |

Dark mode continues to use existing `--bg`, `--text`, and related variables; corporate rules reference those tokens so themes stay consistent.

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
- **Press feedback:** buttons scale to 0.98 on `:active`.

## Buttons

| Intent | Class / pattern | Color |
|--------|-----------------|-------|
| Use / partial consume | `.btn-warning`, `.item-detail-action--use` | Warning gradient |
| Used all / confirm | `.btn-success`, `.item-detail-action--all` | Success gradient |
| Recipe / AI | `.btn-accent`, `.item-detail-action--recipe` | Accent gradient |
| Discard | `.item-detail-action--throw`, `.btn-danger` | Outline or solid danger |
| Secondary / cancel | `.btn-secondary` | Neutral border |

**Idle auto-submit** (Add form 30s, Use form 15s for pcs/conf only): reverse `linear-gradient` wipe on the submit button; hint text below in `--text-muted`. Grams/ml Use forms require manual entry — no idle submit.

## Inventory list

- **Nav label:** “List” / “Lista” (storage location tabs keep Pantry/Fridge/Freezer names).
- **Tap** or **swipe left:** open Use quantity screen.
- **Swipe right:** edit product.
- **Product detail sheet:** 2×2 action grid from dashboard items, expiry alerts, and quick-access chips — not from a plain list tap.
- **Onboarding:** one-time swipe demo on first list open (`localStorage` key `evershelf_inv_swipe_demo_v1`).
- **No static hints:** v1.7.56 persistent banner and edge labels are hidden via CSS (`.inv-swipe-guide`, `.inv-swipe-edge`, `.inv-swipe-hint { display: none }`).
- **During swipe:** colored row backgrounds with action labels (`.inv-swipe-bg-left` / `-right`).
- **Input:** pointer events (mouse drag + touch).

## Modals

- Header: title left, icon actions right (edit ✏️, close ✕).
- Hero block: image + quantity pill + status chips.
- Meta rows: label left, value right.
- Action grid: 2×2 on mobile, equal height tiles.
- Overlay: semi-transparent backdrop with blur.

## Do / don’t

- **Do** reuse CSS variables; avoid one-off hex colors.
- **Do** keep one font family and two weights (600/700 for emphasis).
- **Do** load `corporate.css` after `style.css` when adding new pages.
- **Don’t** mix unrelated button heights on the same row.
- **Don’t** reintroduce persistent swipe text hints — use the one-time demo instead.
- **Don’t** use Italian copy in Markdown docs (English only).
