#!/usr/bin/env php
<?php
/**
 * One-shot triage: comment + close resolved auto-report bugs; reply on #200 (keep open).
 * Usage: php scripts/triage-open-issues.php [--dry-run]
 */
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/lib/github.php';
require_once __DIR__ . '/../api/lib/constants.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$repo   = GH_REPO;
$token  = _ghToken();

if ($token === '') {
    fwrite(STDERR, "ERROR: GH_ISSUE_TOKEN not configured\n");
    exit(1);
}

function ghApi(string $token, string $method, string $url, array $payload = []): array {
    $ch = curl_init($url);
    $headers = [
        'Authorization: token ' . $token,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: EverShelf-Triage/1.0',
        'Content-Type: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http_code' => $code, 'body' => json_decode($raw ?: '{}', true) ?: []];
}

function commentIssue(string $token, string $repo, int $num, string $body, bool $dryRun): bool {
    if ($dryRun) {
        echo "[dry-run] comment #$num\n";
        return true;
    }
    $r = ghApi($token, 'POST', "https://api.github.com/repos/$repo/issues/$num/comments", ['body' => $body]);
    if ($r['http_code'] >= 200 && $r['http_code'] < 300) {
        echo "OK comment #$num\n";
        return true;
    }
    fwrite(STDERR, "FAIL comment #$num HTTP {$r['http_code']}: " . json_encode($r['body']) . "\n");
    return false;
}

function closeIssue(string $token, string $repo, int $num, bool $dryRun): bool {
    if ($dryRun) {
        echo "[dry-run] close #$num\n";
        return true;
    }
    $r = ghApi($token, 'PATCH', "https://api.github.com/repos/$repo/issues/$num", ['state' => 'closed']);
    if ($r['http_code'] >= 200 && $r['http_code'] < 300) {
        echo "OK close #$num\n";
        return true;
    }
    fwrite(STDERR, "FAIL close #$num HTTP {$r['http_code']}: " . json_encode($r['body']) . "\n");
    return false;
}

// ── #200: reply only, keep OPEN ─────────────────────────────────────────────
$body200 = <<<'MD'
Ciao Marco, grazie per la segnalazione dettagliata.

Il messaggio **«Impossibile contattare il server»** compare quando il browser **non riesce a completare** la richiesta a `api/index.php?action=health_check`. Quindi phpinfo funziona, ma **l'endpoint API no** (404, redirect, TLS, path sbagliato, ecc.).

### Check rapidi (dalla macchina dove apri il browser)

```bash
curl -sv "https://TUO-DOMINIO/api/index.php?action=ping"
curl -sv "https://TUO-DOMINIO/api/index.php?action=health_check"
```

Se uno dei due fallisce: DevTools → **Network** → URL esatto e **status code** della richiesta `health_check`.

### Cause frequenti con Traefik + Docker Swarm

1. **Routing incompleto** — Traefik deve inoltrare `/` **e** `/api/*`, non solo la homepage.
2. **Redirect HTTPS** — dietro Traefik serve `X-Forwarded-Proto: https`, oppure disabilitare il redirect in `.htaccess`. Nelle immagini recenti il Dockerfile imposta `SetEnvIf X-Forwarded-Proto "https" HTTPS=on`.
3. **Sottopath** — EverShelf usa URL relativi (`api/index.php`); se l'app è su `/sottocartella/`, l'URL pubblico deve essere coerente.
4. **Volume `data/`** — al primo avvio può essere quasi vuoto; assicurati permessi scrivibili:
   ```bash
   docker exec -it CONTAINER chown -R www-data:www-data /var/www/html/data
   docker exec -it CONTAINER chmod -R 775 /var/www/html/data
   ```
5. **`API_TOKEN` in `.env`** — se impostato, compare un prompt token (non «server non raggiungibile»).

### Per il passo successivo

Puoi condividere:
- URL pubblico esatto (con path)
- Output dei due `curl` sopra
- Screenshot Network tab su `health_check`
- Labels Traefik del servizio (router + middlewares)

Resta aperta finché non confermi che `ping`/`health_check` rispondono — poi chiudiamo insieme.
MD;

commentIssue($token, $repo, 200, $body200, $dryRun);

// ── Resolved auto-report bugs ───────────────────────────────────────────────
$bugs = [
    198 => "Risolto in develop: `PRAGMA busy_timeout` portato a 10s e `dbWithRetry()` su `updateInventory` per ritentare su SQLITE_BUSY quando cron smart-shopping e PWA scrivono in parallelo.",
    199 => "Duplicato di #198 — stesso evento (`inventory_update` → database locked). Fix: retry + busy_timeout aumentato.",
    196 => "Risolto in v1.7.38+: `saveProduct` intercetta `UNIQUE constraint failed: products.barcode`, fa merge sul prodotto esistente o risponde 409 JSON (`barcode_already_used`) invece di HTTP 500.",
    197 => "Conseguenza lato PWA del crash PHP #196 — risolto con gestione barcode duplicato in `saveProduct`.",
    195 => "Risolto: `EverLog::request()` ora riceve sempre stringhe — `\$method = (string)(\$_SERVER['REQUEST_METHOD'] ?? 'GET')` (fix CLI/cron che passavano null).",
    193 => "Stesso root cause di #195 (fatal TypeError su `EverLog::request` con method null da CLI). Fix già in develop.",
    194 => "Risolto: `_applySpesaScanUI` usava `currentPage` (inesistente) → corretto in `_currentPageId`.",
    192 => "Risolto: in `renderShoppingItems` la variabile `enriched` veniva referenziata prima della dichiarazione (TDZ). Ora `enrichedRaw` → `_dedupeShoppingByGeneric` → `enriched`.",
    191 => "Risolto: in `_runStartupCheck` `setProgress` è dichiarata prima delle chiamate e `barEl` inizializzato prima dell'uso (niente più TDZ).",
    134 => "Segnalazione auto-report su volume Docker non scrivibile. Mitigazioni: `_ensureDataDir()`, `_ensureDbWritable()`, Dockerfile `chown www-data`. Su Swarm: `chown -R www-data:www-data data` al primo boot.",
    184 => "Correlato a #134: SQLite readonly quando `data/` o `evershelf.db` non sono scrivibili. Fix operativo + chmod WAL/SHM sidecar in `_ensureDbWritable()`.",
];

foreach ($bugs as $num => $msg) {
    commentIssue($token, $repo, $num, $msg . "\n\n_Chiuso dopo triage — fix in develop._", $dryRun);
    closeIssue($token, $repo, $num, $dryRun);
}

// Feature/enhancement issues stay OPEN — do not bulk-close backlog items here.

echo "Done.\n";
