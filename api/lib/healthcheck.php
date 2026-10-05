<?php
/**
 * EverShelf — cron watchdog (dead man's switch).
 *
 * A cron job that stops running leaves no trace: the smart list simply goes
 * stale while the settings page still looks fine. One HTTP ping at the end of
 * every CLI job turns that silence into a message on a channel the user already
 * reads. Two free services understand the same ping URL, so one setting covers
 * both:
 *
 *   Healthchecks.io   https://hc-ping.com/<uuid>             (free, no card)
 *   Uptime Kuma       https://kuma.example/api/push/<token>  (self-hosted)
 *
 * Configuration — optional, and a no-op when unset:
 *   NOTIFY_HEALTHCHECK_URL            ping URL shared by every job
 *   NOTIFY_HEALTHCHECK_URL_<JOB>      per-job override, e.g.
 *                                     NOTIFY_HEALTHCHECK_URL_SMART_SHOPPING
 *
 * The URL shape decides the protocol:
 *   - without a query string → Healthchecks.io style: the state is a path suffix
 *     (`…/uuid`, `…/uuid/fail`, `…/uuid/start`) and the detail text is the POST
 *     body (it shows up as the check's log).
 *   - with a query string → Uptime Kuma push style: `&status=up|down&msg=<detail>`
 *     is appended and the request is a GET.
 *
 * Privacy: the URL *is* the credential (whoever knows the UUID can silence the
 * alarm and read the check name), so it is never logged, never returned by the
 * API and never put into an error string — exactly like the ntfy topic.
 *
 * The last outcome of every job is also recorded in data/cron_health.json, so
 * Settings → 🔔 Notifications can show the last run even with no URL configured.
 */

/** The CLI jobs this watchdog watches (and the per-job env overrides it accepts). */
const EVERSHELF_HEALTHCHECK_JOBS = ['smart_shopping', 'barcode_catalog', 'mealie_cache'];

/** States accepted from callers; anything else means "ok". */
const EVERSHELF_HEALTHCHECK_STATES = ['start', 'ok', 'fail'];

/** Env key holding the per-job override (smart_shopping → …_SMART_SHOPPING). */
function evershelfHealthcheckEnvKey(string $job): string
{
    return 'NOTIFY_HEALTHCHECK_URL_' . strtoupper(str_replace('-', '_', $job));
}

/**
 * Pure: pick the URL to use from the two configured values.
 * The per-job override wins; a value that is set but is not a plain http(s) URL
 * yields '' (no ping) instead of silently falling back to the other one.
 */
function evershelfHealthcheckResolveUrl(string $perJob, string $shared): string
{
    foreach ([$perJob, $shared] as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        return evershelfNotifyUrlValid($raw) ? $raw : '';
    }
    return '';
}

/**
 * Ping URL for one job: the per-job override wins, the shared URL is the
 * fallback. A value that is set but is not a plain http(s) URL is ignored with a
 * warning instead of being used (it would otherwise leak into an error string).
 */
function evershelfHealthcheckUrl(string $job): string
{
    $perJob = (string)env(evershelfHealthcheckEnvKey($job), '');
    $shared = (string)env('NOTIFY_HEALTHCHECK_URL', '');
    $url    = evershelfHealthcheckResolveUrl($perJob, $shared);

    if ($url === '' && (trim($perJob) !== '' || trim($shared) !== '')) {
        EverLog::warn('Healthcheck ping URL ignored', [
            'event'  => 'healthcheck_url_invalid',
            'job'    => $job,
            'source' => trim($perJob) !== '' ? 'per_job' : 'shared',
        ]);
    }

    return $url;
}

/** True when at least one job has a usable ping URL. */
function evershelfHealthcheckConfigured(): bool
{
    foreach (EVERSHELF_HEALTHCHECK_JOBS as $job) {
        if (evershelfHealthcheckUrl($job) !== '') {
            return true;
        }
    }
    return false;
}

/** First job with a usable URL ('' when none) — used by the manual test ping. */
function evershelfHealthcheckFirstJob(): string
{
    foreach (EVERSHELF_HEALTHCHECK_JOBS as $job) {
        if (evershelfHealthcheckUrl($job) !== '') {
            return $job;
        }
    }
    return '';
}

/** Normalise a state name; anything unknown means "ok". */
function evershelfHealthcheckState(string $state): string
{
    $state = strtolower(trim($state));

    return in_array($state, EVERSHELF_HEALTHCHECK_STATES, true) ? $state : 'ok';
}

/** Self-signed LAN servers: the same opt-in as the notifier. */
function evershelfHealthcheckVerifyTls(): bool
{
    return env('NOTIFY_INSECURE_SSL', 'false') !== 'true';
}

/**
 * Pure: turn (url, state, detail) into the request to send.
 *
 * @return array{url:string,method:string,body:string}
 */
function evershelfHealthcheckRequest(string $url, string $state, string $detail = ''): array
{
    $state  = evershelfHealthcheckState($state);
    $detail = evershelfNotifyHeaderSafe($detail, 300);
    $query  = (string)(parse_url($url)['query'] ?? '');

    if ($query !== '') {
        // Uptime Kuma push URL: state and message travel as query parameters (GET).
        $msg = $state === 'start' ? 'start' : $state;
        if ($detail !== '') {
            $msg .= ': ' . $detail;
        }

        return [
            'url'    => $url . '&status=' . ($state === 'fail' ? 'down' : 'up') . '&msg=' . rawurlencode($msg),
            'method' => 'GET',
            'body'   => '',
        ];
    }

    // Healthchecks.io ping URL: the state is a path suffix, the log is the body.
    $target = rtrim($url, '/');
    if ($state === 'fail') {
        $target .= '/fail';
    } elseif ($state === 'start') {
        $target .= '/start';
    }

    return ['url' => $target, 'method' => 'POST', 'body' => $state === 'start' ? '' : $detail];
}

/**
 * Send one ping to an explicit URL. No config lookup, no bookkeeping: the
 * building block behind evershelfHealthcheckPing() and the test action.
 *
 * @return array{ok:bool,http:int,error:string}
 */
function evershelfHealthcheckSend(string $url, string $state, string $detail = '', int $timeout = 10): array
{
    if (!evershelfNotifyUrlValid($url)) {
        return ['ok' => false, 'http' => 0, 'error' => 'invalid_url'];
    }
    $req = evershelfHealthcheckRequest($url, $state, $detail);

    return evershelfNotifyRequest(
        $req['method'],
        $req['url'],
        ['Content-Type: text/plain; charset=utf-8'],
        $req['body'],
        $timeout,
        evershelfHealthcheckVerifyTls()
    );
}

/**
 * Ping the watchdog for one job and record the outcome.
 *
 * Never throws, never logs the URL, and a "start" ping is not recorded: only
 * terminal states describe the health of a job.
 *
 * @param bool $record false for a manual test ping — it must not rewrite the
 *                     last run the Settings panel shows for the real job.
 * @return array{job:string,state:string,configured:bool,sent:bool,ok:bool,http:int,error:string}
 */
function evershelfHealthcheckPing(string $job, string $state = 'ok', string $detail = '', bool $record = true): array
{
    $state = evershelfHealthcheckState($state);
    $out   = [
        'job'        => $job,
        'state'      => $state,
        'configured' => false,
        'sent'       => false,
        'ok'         => false,
        'http'       => 0,
        'error'      => '',
    ];

    $url = evershelfHealthcheckUrl($job);
    if ($url === '') {
        $out['error'] = 'healthcheck_not_configured';
    } else {
        $out['configured'] = true;
        $res          = evershelfHealthcheckSend($url, $state, $detail);
        $out['sent']  = true;
        $out['ok']    = !empty($res['ok']);
        $out['http']  = (int)($res['http'] ?? 0);
        $out['error'] = (string)($res['error'] ?? '');
        if (!$out['ok']) {
            EverLog::warn('Healthcheck ping failed', [
                'event' => 'healthcheck_ping_failed',
                'job'   => $job,
                'state' => $state,
                'http'  => $out['http'],
                'error' => $out['error'],
            ]);
        }
    }

    if ($record && $state !== 'start') {
        evershelfHealthcheckRecord($job, $out);
    }

    return $out;
}

/**
 * Persist the last outcome of one job. Best effort: a read-only data/ directory
 * must never turn a successful cron run into an error.
 *
 * "state" is the health of the *job*, "delivered" is whether the ping reached the
 * watchdog — a sync can fail while its alert is delivered perfectly, and the two
 * must not be confused when the panel reports the last run.
 */
function evershelfHealthcheckRecord(string $job, array $out): void
{
    if (!in_array($job, EVERSHELF_HEALTHCHECK_JOBS, true)) {
        return;
    }
    try {
        $data       = evershelfHealthcheckRawStatus();
        $data[$job] = [
            'ts'        => time(),
            'state'     => evershelfHealthcheckState((string)($out['state'] ?? 'ok')),
            'sent'      => !empty($out['sent']),
            'delivered' => !empty($out['ok']),
            'http'      => (int)($out['http'] ?? 0),
        ];
        @file_put_contents(CRON_HEALTH_PATH, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    } catch (Throwable $e) {
        EverLog::warn('Healthcheck record failed', [
            'event' => 'healthcheck_record_failed',
            'job'   => $job,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * Raw content of the status file, restricted to the known job keys (an entry a
 * user hand-edited into the file must not break the Settings panel).
 *
 * @return array<string,array<string,mixed>>
 */
function evershelfHealthcheckRawStatus(): array
{
    $raw  = @file_get_contents(CRON_HEALTH_PATH);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        return [];
    }

    return array_intersect_key($data, array_flip(EVERSHELF_HEALTHCHECK_JOBS));
}

/**
 * Last recorded outcome per job, ready for get_settings / the Settings panel.
 * `state` is the job health (what the badge shows) and is taken from the record
 * verbatim; `delivered` only says whether the ping itself reached the watchdog.
 *
 * @return array<string,array{ts:int,state:string,ok:bool,sent:bool,delivered:bool,http:int}>
 */
function evershelfHealthcheckStatus(): array
{
    $out = [];
    foreach (evershelfHealthcheckRawStatus() as $job => $entry) {
        if (!is_array($entry) || empty($entry['ts'])) {
            continue;
        }
        $state     = evershelfHealthcheckState((string)($entry['state'] ?? 'ok'));
        $out[$job] = [
            'ts'        => (int)$entry['ts'],
            'state'     => $state,
            'ok'        => $state !== 'fail',
            'sent'      => !empty($entry['sent']),
            'delivered' => !empty($entry['delivered']),
            'http'      => (int)($entry['http'] ?? 0),
        ];
    }

    return $out;
}
