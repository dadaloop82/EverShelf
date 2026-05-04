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
- `assets/css/style.css` — all styles
- `api/index.php` — all API endpoints
- `api/database.php` — SQLite schema and migrations
- `translations/*.json` — i18n strings

### 5. Test

```bash
# Check PHP syntax
php -l api/index.php
php -l api/database.php

# Check JS syntax
node --check assets/js/app.js
```

There are no automated JS tests yet — manual testing in the browser is the current approach. If you add a feature, test the full flow: add, use, undo.

### 6. Commit

Use [Conventional Commits](https://www.conventionalcommits.org/):

```bash
git commit -m "feat(inventory): add bulk delete"
git commit -m "fix(scale): handle BLE disconnect during countdown"
git commit -m "docs: update kiosk setup guide"
git commit -m "chore: bump version to 1.8.0"
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

GitHub Actions runs on every push to `develop` and `main`:

1. **PHP lint** — `php -l` on all PHP files
2. **JS syntax check** — `node --check assets/js/app.js`
3. **Translation validation** — checks that all language files have the same keys
4. **Docker build** — verifies the Docker image builds successfully
5. **Android build** — (on tagged commits) builds Kiosk and Scale Gateway APKs

---

## Adding Translations

See the full guide in [Translations](Translations).

Short version:
1. Copy `translations/it.json` → `translations/xx.json`
2. Translate all values
3. Add `'xx'` to `SUPPORTED_LANGUAGES` in `app.js`
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
