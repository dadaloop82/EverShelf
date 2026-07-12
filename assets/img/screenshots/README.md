# Screenshots

Add screenshots here to showcase the app in the README.

Since **v1.7.57 (Corporate UI)**, prefer captures that show the unified green palette, gradient buttons, pill tabs, and card-based lists.

## Recommended screenshots

Take screenshots of these pages for the README `Screenshots` section:

1. **dashboard.png** — Dashboard with corporate stat cards and expiry alerts
2. **list.png** — Inventory **List** page (not legacy “Pantry” nav label) with location tabs and swipe-ready rows
3. **list-use.png** — Use quantity screen with idle countdown (optional)
4. **item-detail.png** — Product detail sheet with 2×2 action grid (from dashboard or quick access)
5. **scan.png** — Barcode scanning page
6. **recipe.png** — Generated recipe with cooking mode
7. **shopping.png** — Shopping list with urgency styling
8. **chat.png** — Gemini Chef AI conversation
9. **settings.png** — Settings page with pill tabs
10. **setup.png** — First-run setup wizard

## Tips for Corporate UI shots

- Use **light mode** first — primary green (`#2d5016`) reads best on the default `--bg` tint.
- Capture **List** swipe state if possible: row shifted left/right with colored action background.
- Include at least one **modal** (product sheet or Use form) to show blurred overlay and top accent border.
- Dark mode: optional second set under `dark/` subfolder.

## How to add

1. Take screenshots on a mobile device or using Chrome DevTools device emulation
2. Recommended size: 375×812 (iPhone X viewport)
3. Save as PNG with descriptive names
4. Update the README.md `## Screenshots` section to reference them:

```markdown
## Screenshots

| Dashboard | List | Item detail |
|:-:|:-:|:-:|
| ![Dashboard](assets/img/screenshots/dashboard.png) | ![List](assets/img/screenshots/list.png) | ![Detail](assets/img/screenshots/item-detail.png) |
```
