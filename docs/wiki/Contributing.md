# 🤝 Contributing

Contributions of all kinds are welcome — bug fixes, new features, translations, documentation improvements.

---

## Getting Started

### 1. Fork and clone

```bash
git clone https://github.com/YOUR_USERNAME/EverShelf.git
cd EverShelf
```

### 2. Create a branch

```bash
git checkout -b feature/my-feature
# or
git checkout -b fix/my-bug-fix
```

### 3. Set up a local server

```bash
# Option A: PHP built-in server
php -S localhost:8080

# Option B: Docker
docker compose up -d
```

Open `http://localhost:8080` in your browser.

### 4. Make your changes

The app has **no build step**. Edit files directly and refresh the browser.

Key files:
- `assets/js/app.js` — all frontend logic
- `assets/css/style.css` (+ `corporate.css`, `elegant.css`) — styles
- `api/index.php` — all API endpoints
- `api/database.php` — SQLite schema and migrations
- `translations/*.json` — i18n strings

### 5. Test

Run the same gates CI runs, before you push:

```bash
# PHP syntax (same as CI)
find api -name '*.php' -exec php -l {} \;

# JS syntax (same as CI; mcp-server is ESM → --check, not -c)
node -c assets/js/app.js && node -c sw.js
for f in mcp-server/src/*.js; do node --check "$f"; done

# Translation files are valid JSON
python3 -c "import json; json.load(open('translations/it.json'))"
```

Then the regression suite and the audits — **these run locally today**, CI does not
have them yet (see the CI/CD section):

```bash
php scripts/test-shopping-guards.php
php scripts/test-internal-shopping-cleanup.php
php scripts/test-notify.php
php scripts/test-healthcheck.php
php scripts/test-calendar-ics.php
php scripts/test-recipe-shopping.php
php scripts/test-settings-nav.php     # settings sections ↔ tabs ↔ panels ↔ locales
php scripts/test-setup-assistant.php  # SETTINGS_CHECKLIST ↔ tabs ↔ wizard steps
php scripts/test-i18n-icons.php        # no label prints its icon twice (all locales)
php scripts/test-html-in-text.php      # no HTML reaches a text-only surface
php scripts/test-dashboard-panels.php  # dashboard rotation: phases ↔ sections ↔ bar fills
php scripts/test-product-kind-prefix.php # genre leading every article title (no double prefix)
php scripts/test-auto-favorite.php     # used-often products become favourites; an unstar wins
python3 scripts/i18n-audit.py          # keys used in code exist in every locale
python3 scripts/i18n-value-audit.py   # keys whose value is still English (report)
shellcheck -S warning backup.sh scripts/*.sh
```

There are no automated **browser** tests yet — for UI work, test the full flow by hand:
add, use, undo.

### 6. Commit

Use [Conventional Commits](https://www.conventionalcommits.org/):

```bash
git commit -m "feat(inventory): add bulk delete"
git commit -m "fix(scale): handle BLE disconnect during countdown"
git commit -m "docs: update kiosk setup guide"
git commit -m "chore: bump version to 1.9.2"
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

Scopes: `inventory`, `ai`, `shopping`, `cooking`, `scale`, `kiosk`, `gateway`, `webapp`, `api`, `db`

### 7. Push and open a PR

```bash
git push origin feature/my-feature
```

Open a Pull Request against the `develop` branch (not `main`).

---

## Branch Strategy

| Branch | Purpose |
|--------|---------|
| `main` | Production — auto-deployed, never commit directly |
| `develop` | Integration branch — all PRs target here |
| `feature/*` | New features |
| `fix/*` | Bug fixes |

CI auto-merges `develop → main` on every push to `develop`.

---

## CI / CD Pipeline

`.github/workflows/ci.yml` runs on every push to `develop`/`main` and on PRs to `main`:

1. **PHP syntax** — `php -l` on every file under `api/`
2. **JS syntax** — `node -c assets/js/app.js`
3. **Docker build smoke test** — builds the image and starts the container
4. **Translation validation** — every `translations/*.json` is valid JSON and has the
   same top-level keys as `it.json`
5. **Auto-merge `develop → main`** — runs when all four checks pass on `develop`
6. **Create GitHub Release** — reads the version from `index.html`, tags `vX.Y.Z` when
   the tag does not exist yet, and uses that version's `## [x.y.z]` CHANGELOG section as
   the release body

This is what makes the release ritual mechanical: **bump the version** with
`scripts/bump-version.sh X.Y.Z` (index.html, `manifest.json`, `sw.js`, the `app.js` i18n
token) and **write the `## [X.Y.Z]` CHANGELOG section** before pushing to `develop`;
CI does the merge and the tag. Android APKs (kiosk, health bridge) and the Docker image
have their own workflows and build on their own triggers.

> The regression suite, the i18n audits and `shellcheck` from the step above are **not
> in CI yet** — run them yourself. Wiring them in needs a `workflow`-scoped PAT (the
> ready-made patch lives in the git-ignored `todo/` folder).

---

## Adding Translations

See the full guide in [Translations](Translations).

Short version:
1. Copy `translations/it.json` → `translations/xx.json`
2. Translate all values
3. Add `'xx'` to `_SUPPORTED_LANGS` in `app.js` (the single source for detection and
   the language `<select>`)
4. Open a PR

---

## Reporting Bugs

Open an issue on GitHub. Include:
- Steps to reproduce
- Expected vs. actual behaviour
- Browser/OS version
- Any console errors (F12 → Console)

---

## Code Style

- **PHP:** PSR-12, 4-space indent, type hints where practical
- **JavaScript:** ES2020+, `async/await`, no frameworks, 4-space indent
- **CSS:** BEM-ish class names, CSS custom properties for theming
- **SQL:** parameterized queries (PDO), no raw string interpolation

---

## Adding a New API Endpoint

1. Add a `case 'my_action':` to the router in `api/index.php`
2. Implement `function myAction(PDO $db): void`
3. Add the endpoint to `docs/openapi.yaml`
4. Add translations for any new UI strings to all 3 language files

---

## Security

If you find a security vulnerability, **do not open a public issue**. Email [evershelfproject@gmail.com](mailto:evershelfproject@gmail.com) directly.

Relevant resources:
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- All SQL must use PDO prepared statements
- Never expose API keys in API responses (boolean flags only)
- Use `hash_equals()` for token comparison

---

## License

By contributing you agree that your code will be licensed under the [MIT License](https://github.com/dadaloop82/EverShelf/blob/main/LICENSE).
