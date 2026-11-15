package it.dadaloop.evershelf.health

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.time.LocalDate
import java.util.concurrent.TimeUnit

/** What a sync pass did, in the words the screens show. */
data class SyncOutcome(
    val ok: Boolean,
    val deferred: Boolean,
    val sent: Int,
    val pending: Int,
    val message: String,
)

/**
 * The one place where a day becomes an upload.
 *
 * Order is deliberate and never inverted: read the phone → store the day locally → prove the
 * server answers → upload the queue. A pass that stops at step 3 has still DONE its job (the
 * data is safe on the phone); only a failed upload of a reachable server is a failure.
 */
object SyncEngine {
    private const val WORK_PERIODIC = "evershelf_health_periodic"
    private const val WORK_ONCE = "evershelf_health_once"
    private const val MAX_UPLOADS_PER_PASS = 31

    suspend fun syncNow(ctx: Context, trigger: String = "manual"): SyncOutcome =
        withContext(Dispatchers.IO) {
            val app = ctx.applicationContext
            if (!Prefs.isConfigured(app)) {
                Logs.warn(app, "sync", "not paired — nothing to do")
                return@withContext SyncOutcome(false, false, 0, 0, "not_configured")
            }

            // 1) The phone is read even when the server is away: the day exists on disk.
            val today = LocalDate.now()
            var readError: String? = null
            try {
                val payload = HealthConnectReader.readToday(app)
                if (payload.optString("error", "").isNotEmpty()) {
                    readError = payload.optString("error")
                }
                if (hasAnyMetric(payload)) {
                    HealthDb(app).upsertDay(today.toString(), payload, pending = true)
                    Logs.info(app, "sync", "day ${today} stored locally (${payload.length()} fields)")
                }
            } catch (e: Throwable) {
                readError = e.message
                Logs.error(app, "sync", e)
            }

            // 2) The optional Wi-Fi guard.
            if (Prefs.wifiOnly(app) && !ServerReachability.onWifi(app)) {
                val pending = HealthDb(app).pendingCount()
                BridgeNotifier.updateWaiting(app, pending)
                HealthDb(app).logSync(trigger, false, "deferred: not on Wi-Fi")
                return@withContext SyncOutcome(true, true, 0, pending, "not_wifi")
            }

            // 3) The server must ANSWER. This is the rule, not a preference.
            if (!ServerReachability.check(app)) {
                val pending = HealthDb(app).pendingCount()
                BridgeNotifier.updateWaiting(app, pending)
                HealthDb(app).logSync(trigger, false, "deferred: server unreachable")
                Logs.info(app, "sync", "deferred: server unreachable, $pending day(s) queued")
                return@withContext SyncOutcome(true, true, 0, pending, "unreachable")
            }

            // 4) Upload what is queued, oldest first.
            val db = HealthDb(app)
            val queued = db.pendingDays(MAX_UPLOADS_PER_PASS)
            var sent = 0
            for (day in queued) {
                val payload = day.obj()
                if (!payload.has("date")) payload.put("date", day.date)
                val res = EverShelfClient.ingest(Prefs.url(app), Prefs.token(app), payload)
                res.onSuccess { json ->
                    val resolved = json.optString("_resolved_base", "")
                    if (resolved.isNotBlank() && resolved != Prefs.url(app)) {
                        Prefs.saveServer(app, resolved, Prefs.token(app))
                    }
                    db.markSynced(day.date)
                    sent++
                }
                res.onFailure { err ->
                    val message = err.message ?: "error"
                    db.logSync(trigger, false, "upload ${day.date} failed: $message")
                    Logs.warn(app, "sync", "upload ${day.date} failed: $message")
                    BridgeNotifier.updateFailed(app, message)
                    ErrorReporter.report(app, "sync_failed", "Upload of ${day.date} failed: $message", mapOf("date" to day.date))
                    return@withContext SyncOutcome(false, false, sent, db.pendingCount(), message)
                }
            }

            // A successful upload means the server was there: keep the verdict warm.
            ServerReachability.markReachable()
            if (sent > 0 || trigger != "worker") {
                val last = db.pendingDays(1).firstOrNull()?.json ?: HealthDb(app).recentDays(1).firstOrNull()?.json ?: ""
                if (last.isNotEmpty()) Prefs.saveLastSync(app, last)
            }
            val pendingLeft = db.pendingCount()
            db.logSync(trigger, true, "sent $sent day(s), $pendingLeft queued")
            Logs.info(app, "sync", "sent $sent day(s), $pendingLeft queued${readError?.let { " (read: $it)" } ?: ""}")
            BridgeNotifier.updateOk(app, pendingLeft)
            SyncOutcome(true, false, sent, pendingLeft, if (readError != null) "read_partial" else "ok")
        }

    /** Sends ONE day the household asked for (Diary → "Send this day"). */
    suspend fun sendDay(ctx: Context, date: String): Result<Unit> = withContext(Dispatchers.IO) {
        val app = ctx.applicationContext
        if (!Prefs.isConfigured(app)) return@withContext Result.failure(IllegalStateException("not_configured"))
        val db = HealthDb(app)
        val row = db.getDay(date) ?: return@withContext Result.failure(IllegalStateException("missing_day"))
        if (!ServerReachability.check(app, force = true)) {
            db.logSync("manual", false, "$date deferred: server unreachable")
            return@withContext Result.failure(IllegalStateException("unreachable"))
        }
        val payload = row.obj().put("date", date)
        EverShelfClient.ingest(Prefs.url(app), Prefs.token(app), payload).fold(
            onSuccess = {
                db.markSynced(date)
                Prefs.saveLastSync(app, payload.toString())
                db.logSync("manual", true, "$date sent")
                BridgeNotifier.updateOk(app, db.pendingCount())
                Result.success(Unit)
            },
            onFailure = { err ->
                db.logSync("manual", false, "$date failed: ${err.message}")
                Result.failure(err)
            },
        )
    }

    /**
     * Reads the last [days] days already on this phone into the diary, so charts are useful on
     * the first run. Whatever the server is doing, this only writes to the phone.
     */
    suspend fun backfill(ctx: Context, days: Int = Prefs.backfillDays(ctx), onProgress: (Int) -> Unit = {}): Int =
        withContext(Dispatchers.IO) {
            val app = ctx.applicationContext
            val db = HealthDb(app)
            var stored = 0
            val today = LocalDate.now()
            for (i in 0 until days) {
                val date = today.minusDays(i.toLong())
                try {
                    val payload = HealthConnectReader.readDay(app, date)
                    if (hasAnyMetric(payload)) {
                        db.upsertDay(date.toString(), payload, pending = false, localOnly = true)
                        stored++
                    }
                } catch (e: Throwable) {
                    Logs.warn(app, "backfill", "$date: ${e.message}")
                }
                onProgress(i + 1)
            }
            Logs.info(app, "backfill", "stored $stored day(s) of $days")
            stored
        }

    fun schedulePeriodic(ctx: Context) {
        if (!Prefs.autoSync(ctx)) {
            WorkManager.getInstance(ctx).cancelUniqueWork(WORK_PERIODIC)
            return
        }
        val minutes = Prefs.syncIntervalMin(ctx).toLong()
        val request = PeriodicWorkRequestBuilder<HealthSyncWorker>(minutes, TimeUnit.MINUTES)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.MINUTES)
            .build()
        WorkManager.getInstance(ctx).enqueueUniquePeriodicWork(
            WORK_PERIODIC, ExistingPeriodicWorkPolicy.UPDATE, request
        )
    }

    /** One pass soon (network came back, notification action, app opened). */
    fun requestImmediate(ctx: Context, delayMs: Long = 0L) {
        if (!Prefs.isConfigured(ctx)) return
        val request = OneTimeWorkRequestBuilder<HealthSyncWorker>()
            .setInitialDelay(delayMs, TimeUnit.MILLISECONDS)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.MINUTES)
            .build()
        WorkManager.getInstance(ctx).enqueueUniqueWork(WORK_ONCE, ExistingWorkPolicy.REPLACE, request)
    }

    private fun hasAnyMetric(payload: JSONObject): Boolean {
        val ignored = setOf("date", "source", "synced_at", "error")
        val keys = payload.keys()
        while (keys.hasNext()) {
            if (!ignored.contains(keys.next())) return true
        }
        return false
    }
}

class HealthSyncWorker(appContext: Context, params: WorkerParameters) :
    CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result {
        val app = applicationContext
        if (!Prefs.autoSync(app) || !Prefs.isConfigured(app)) return Result.success()
        val outcome = SyncEngine.syncNow(app, "worker")
        return when {
            outcome.ok -> Result.success()
            // A failure is retried with backoff; a deferral is a success (nothing was lost).
            else -> Result.retry()
        }
    }
}
