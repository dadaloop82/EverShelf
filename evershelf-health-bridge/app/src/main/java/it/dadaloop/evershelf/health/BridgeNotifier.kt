package it.dadaloop.evershelf.health

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** The single silent notification that says what the bridge is doing. */
object BridgeNotifier {
    const val CHANNEL_ID = "evershelf_health_bridge"
    const val NOTIF_ID = 42

    fun ensureChannel(ctx: Context) {
        if (Build.VERSION.SDK_INT < 26) return
        val nm = ctx.getSystemService(NotificationManager::class.java) ?: return
        val channel = NotificationChannel(
            CHANNEL_ID,
            ctx.getString(R.string.notif_channel_name),
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = ctx.getString(R.string.notif_channel_desc)
            setShowBadge(false)
        }
        nm.createNotificationChannel(channel)
    }

    fun build(ctx: Context, text: String): Notification {
        ensureChannel(ctx)
        val open = PendingIntent.getActivity(
            ctx, 0, Intent(ctx, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val sync = PendingIntent.getService(
            ctx, 1,
            Intent(ctx, KeepAliveService::class.java).setAction(KeepAliveService.ACTION_SYNC),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        return NotificationCompat.Builder(ctx, CHANNEL_ID)
            .setContentTitle(ctx.getString(R.string.notif_title))
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setSmallIcon(R.drawable.ic_stat_health)
            .setContentIntent(open)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .addAction(0, ctx.getString(R.string.notif_action_sync), sync)
            .build()
    }

    private fun notify(ctx: Context, text: String) {
        if (!Prefs.notifications(ctx)) return
        ensureChannel(ctx)
        val nm = ctx.getSystemService(NotificationManager::class.java) ?: return
        runCatching { nm.notify(NOTIF_ID, build(ctx, text)) }
    }

    fun updateOk(ctx: Context, pending: Int) {
        val last = lastSyncText(ctx)
        val text = if (pending > 0) {
            ctx.getString(R.string.notif_body, last) + " · " + ctx.getString(R.string.dash_pending, pending)
        } else {
            ctx.getString(R.string.notif_sync_ok)
        }
        notify(ctx, text)
    }

    fun updateWaiting(ctx: Context, pending: Int) {
        notify(ctx, ctx.getString(R.string.notif_waiting, pending))
    }

    fun updateFailed(ctx: Context, message: String) {
        notify(ctx, ctx.getString(R.string.notif_sync_fail, message))
    }

    fun updateSyncing(ctx: Context) {
        notify(ctx, ctx.getString(R.string.notif_syncing))
    }

    fun cancel(ctx: Context) {
        val nm = ctx.getSystemService(NotificationManager::class.java) ?: return
        runCatching { nm.cancel(NOTIF_ID) }
    }

    fun lastSyncText(ctx: Context): String {
        val last = Prefs.lastSync(ctx)
        return if (last == 0L) ctx.getString(R.string.notif_never)
        else SimpleDateFormat("HH:mm", Locale.getDefault()).format(Date(last))
    }
}
