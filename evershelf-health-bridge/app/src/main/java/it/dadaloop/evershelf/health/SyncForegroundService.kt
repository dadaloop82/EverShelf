package it.dadaloop.evershelf.health

import android.app.Service
import android.content.Intent
import android.os.IBinder

/** @deprecated Use [KeepAliveService]; kept so old manifests / PendingIntents still resolve. */
class SyncForegroundService : Service() {
    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        KeepAliveService.sync(applicationContext)
        stopSelf()
        return START_NOT_STICKY
    }
}
