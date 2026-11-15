package it.dadaloop.evershelf.health

import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.IBinder
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch

/**
 * Optional keep-alive with an ongoing notification.
 *
 * Started ONLY from a visible screen ([MainActivity]): Android 15 refuses a `dataSync`
 * foreground service launched from BOOT_COMPLETED, and that refusal was one of the ways the
 * old app died "by itself". A failure here is logged and ignored — WorkManager keeps syncing.
 */
class KeepAliveService : Service() {
    private val scope = CoroutineScope(Dispatchers.Main + SupervisorJob())

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        try {
            BridgeNotifier.ensureChannel(this)
            startForeground(BridgeNotifier.NOTIF_ID, BridgeNotifier.build(this, getString(R.string.notif_body, BridgeNotifier.lastSyncText(this))))
        } catch (e: Throwable) {
            Logs.warn(this, "keepalive", "startForeground refused: ${e.javaClass.simpleName}: ${e.message}")
            stopSelf()
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                stopForeground(STOP_FOREGROUND_REMOVE)
                stopSelf()
                return START_NOT_STICKY
            }
            ACTION_SYNC -> scope.launch {
                BridgeNotifier.updateSyncing(this@KeepAliveService)
                SyncEngine.syncNow(this@KeepAliveService, "notification")
            }
        }
        SyncEngine.schedulePeriodic(this)
        return START_STICKY
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        const val ACTION_SYNC = "it.dadaloop.evershelf.health.SYNC"
        const val ACTION_STOP = "it.dadaloop.evershelf.health.STOP"

        /** Only called with a visible Activity (or from a notification action). */
        fun start(ctx: Context) {
            if (!Prefs.isConfigured(ctx) || !Prefs.keepAlive(ctx)) return
            val intent = Intent(ctx, KeepAliveService::class.java)
            try {
                ContextCompat.startForegroundService(ctx, intent)
            } catch (e: Throwable) {
                // ForegroundServiceStartNotAllowedException on Android 12+/15: the bridge is
                // still fine, WorkManager carries the sync.
                Logs.warn(ctx, "keepalive", "could not start: ${e.javaClass.simpleName}: ${e.message}")
            }
        }

        fun stop(ctx: Context) {
            val intent = Intent(ctx, KeepAliveService::class.java).setAction(ACTION_STOP)
            runCatching { ctx.startService(intent) }
        }
    }
}
