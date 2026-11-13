<?php
/**
 * The OAuth 2.0 `state` of the Google Drive flow: one value, ten minutes, single use.
 *
 * The callback is PUBLIC (Google cannot present the API token) and it OVERWRITES
 * `GDRIVE_REFRESH_TOKEN` with whatever `code` it receives. Without a `state` a third party can
 * hand the server an authorization code of its own (login CSRF) and from then on the nightly
 * backup goes to *their* Drive. Only the hash is stored, and only one value exists at a time:
 * a second flow invalidates the first, and a reused state is rejected.
 *
 * Kept out of api/index.php so the rule can be exercised from a script (see the note in
 * AGENTS.md about tests that run the real code instead of a copy of it).
 */
declare(strict_types=1);

/** Where the hash of the outstanding state lives (inside data/, git-ignored). */
function evershelfGdriveStateFile(): string
{
    return dirname(__DIR__, 2) . '/data/gdrive_oauth_state.json';
}

/** Issue a fresh state, store its hash, return the raw value for the consent URL. */
function evershelfGdriveStateIssue(int $ttlSeconds = 600): string
{
    $state = bin2hex(random_bytes(16));
    $file  = evershelfGdriveStateFile();
    if (is_dir(dirname($file))) {
        @file_put_contents($file, json_encode([
            'hash'    => hash('sha256', $state),
            'expires' => time() + max(60, $ttlSeconds),
        ]), LOCK_EX);
        @chmod($file, 0600);
    }
    return $state;
}

/** True when $state is the one we issued and still fresh — a successful check consumes it. */
function evershelfGdriveStateConsume(string $state): bool
{
    if ($state === '') {
        return false;
    }
    $file = evershelfGdriveStateFile();
    if (!is_file($file)) {
        return false;
    }
    $raw  = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || empty($data['hash']) || empty($data['expires'])) {
        @unlink($file); // unreadable: nothing to protect any more
        return false;
    }
    if ((int)$data['expires'] < time()) {
        @unlink($file);
        return false;
    }
    if (!hash_equals((string)$data['hash'], hash('sha256', $state))) {
        // Somebody else's value. The pending flow is left alone on purpose: deleting it here
        // would let any unauthenticated request cancel the consent the household is doing.
        return false;
    }
    @unlink($file); // single use: this value can never be replayed
    return true;
}
