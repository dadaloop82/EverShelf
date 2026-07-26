package it.dadaloop.evershelf.health

import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.IBinder
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

/**
 * Sticky foreground service with an ongoing notification so OEMs are less likely
 * to kill the Health Bridge / defer WorkManager syncs.
 */
class KeepAliveService : Service() {
    private val job = Job()
    private val scope = CoroutineScope(Dispatchers.Main + job)

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        BridgeNotifier.ensureChannel(this)
        startForeground(BridgeNotifier.NOTIF_ID, BridgeNotifier.buildOngoing(this))
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForeground(BridgeNotifier.NOTIF_ID, BridgeNotifier.buildOngoing(this))
        when (intent?.action) {
            ACTION_SYNC -> scope.launch { runSync() }
            ACTION_STOP -> {
                stopForeground(STOP_FOREGROUND_REMOVE)
                stopSelf()
                return START_NOT_STICKY
            }
        }
        SyncHelper.schedulePeriodic(this)
        return START_STICKY
    }

    private suspend fun runSync() {
        BridgeNotifier.update(this, getString(R.string.notif_syncing))
        val res = SyncHelper.syncNow(this)
        val msg = if (res.isSuccess) getString(R.string.notif_sync_ok)
        else getString(R.string.notif_sync_fail, res.exceptionOrNull()?.message ?: "?")
        BridgeNotifier.update(this, msg)
    }

    override fun onDestroy() {
        job.cancel()
        super.onDestroy()
    }

    companion object {
        const val ACTION_SYNC = "it.dadaloop.evershelf.health.SYNC"
        const val ACTION_STOP = "it.dadaloop.evershelf.health.STOP"

        fun start(ctx: Context) {
            if (!Prefs.isConfigured(ctx)) return
            val i = Intent(ctx, KeepAliveService::class.java)
            ContextCompat.startForegroundService(ctx, i)
        }

        fun sync(ctx: Context) {
            if (!Prefs.isConfigured(ctx)) return
            val i = Intent(ctx, KeepAliveService::class.java).setAction(ACTION_SYNC)
            ContextCompat.startForegroundService(ctx, i)
        }
    }
}
