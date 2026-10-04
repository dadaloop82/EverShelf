#!/usr/bin/env php
<?php
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/lib/github.php';
require_once __DIR__ . '/../api/lib/constants.php';

$token = _ghToken();
if ($token === '') {
    fwrite(STDERR, "ERROR: GH_ISSUE_TOKEN not configured\n");
    exit(1);
}

$body = <<<'MD'
Ciao Marco, grazie per i log — sono statissimi e hanno individuato il bug.

**Non è un problema di rete.** I tuoi `curl` rispondono 200; il server c'è. Il problema è che **prima del JSON** PHP stampa avvisi HTML (Deprecation su `LoggingPDO::prepare` + “Cannot modify header information”). Il browser non riesce a fare `response.json()` e mostra erroneamente *«Impossibile contattare il server»*.

### Due problemi distinti

**1. Too many redirects (Traefik + `.htaccess`)**  
Dietro Traefik la connessione container↔Apache è HTTP, quindi `.htaccess` forza un altro redirect HTTPS → loop.  
Con le tue label (`X-Forwarded-Proto=https`) la soluzione è **non disabilitare** il redirect, ma usare una versione che lo salta quando `X-Forwarded-Proto` è già `https`.

**2. Splash bloccato (bug nostro, PHP 8.2)**  
Con `display_errors` attivo, un avviso di deprecazione corrompe ogni risposta API. Succede su **v1.7.39**; **v1.7.40** non lo correggeva ancora.

### Fix in **v1.7.41** (pubblicato ora)
- `.htaccess`: redirect HTTPS solo se non c'è già `X-Forwarded-Proto: https` (compatibile con le tue label Traefik)
- API: nessun output HTML prima del JSON
- `LoggingPDO`: compatibilità PHP 8.2 (niente più Deprecation)

Release: https://github.com/dadaloop82/EverShelf/releases/tag/v1.7.41

### Cosa fare adesso
1. Aggiorna all'immagine/tag **v1.7.41** (o pull da `main`).
2. **Ripristina** il blocco HTTPS in `.htaccess` (non serve commentarlo).
3. Verifica:
   ```bash
   curl -s "https://<MIO-DOMINIO>/api/index.php?action=ping"
   ```
   Deve rispondere **solo** `{"ok":true,"ts":...}` — senza `<br />` o `Deprecated`.
4. Ricarica l'app: dovresti vedere il wizard di primo avvio (`fresh install` nei tuoi check).

Le label Traefik che hai incollato vanno bene; non servono workaround manuali.

Fammi sapere dopo l'aggiornamento — se il `curl` è pulito e l'app parte, chiudiamo la issue.
MD;

$ch = curl_init('https://api.github.com/repos/' . GH_REPO . '/issues/200/comments');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['body' => $body]),
    CURLOPT_HTTPHEADER     => [
        'Authorization: token ' . $token,
        'Accept: application/vnd.github+json',
        'Content-Type: application/json',
        'User-Agent: EverShelf-Triage/1.0',
    ],
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code < 200 || $code >= 300) {
    fwrite(STDERR, "FAIL HTTP $code: $raw\n");
    exit(1);
}
echo "OK comment posted on #200\n";
