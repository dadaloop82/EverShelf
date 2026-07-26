package it.dadaloop.evershelf.health

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import org.json.JSONObject
import java.util.concurrent.TimeUnit

object SyncHelper {
    private const val TAG = "EverShelfHealthSync"
    private const val WORK = "evershelf_health_periodic"

    suspend fun syncNow(ctx: Context): Result<JSONObject> {
        if (!Prefs.isConfigured(ctx)) {
            return Result.failure(IllegalStateException("not_configured"))
        }
        val payload = HealthConnectReader.readToday(ctx)
        if (payload.has("error") && payload.length() <= 4) {
            return Result.failure(IllegalStateException(payload.optString("error")))
        }
        val res = EverShelfClient.ingest(Prefs.url(ctx), Prefs.token(ctx), payload)
        res.onSuccess { json ->
            val resolved = json.optString("_resolved_base", "")
            if (resolved.isNotBlank() && resolved != Prefs.url(ctx)) {
                Prefs.saveServer(ctx, resolved, Prefs.token(ctx))
            }
            Prefs.saveLastSync(ctx, payload.toString())
            Log.i(TAG, "sync ok")
        }.onFailure {
            Log.w(TAG, "sync fail: ${it.message}")
        }
        return res
    }

    fun schedulePeriodic(ctx: Context) {
        val req = PeriodicWorkRequestBuilder<HealthSyncWorker>(30, TimeUnit.MINUTES)
            .build()
        WorkManager.getInstance(ctx).enqueueUniquePeriodicWork(
            WORK,
            ExistingPeriodicWorkPolicy.UPDATE,
            req
        )
    }
}

class HealthSyncWorker(appContext: Context, params: WorkerParameters) :
    CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result {
        if (!Prefs.autoSync(applicationContext) || !Prefs.isConfigured(applicationContext)) {
            return Result.success()
        }
        return if (SyncHelper.syncNow(applicationContext).isSuccess) Result.success()
        else Result.retry()
    }
}
