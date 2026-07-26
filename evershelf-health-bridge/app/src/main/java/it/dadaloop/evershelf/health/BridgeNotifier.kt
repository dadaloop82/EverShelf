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

object BridgeNotifier {
    const val CHANNEL_ID = "evershelf_health_bridge"
    const val NOTIF_ID = 42

    fun ensureChannel(ctx: Context) {
        if (Build.VERSION.SDK_INT < 26) return
        val nm = ctx.getSystemService(NotificationManager::class.java) ?: return
        val ch = NotificationChannel(
            CHANNEL_ID,
            ctx.getString(R.string.notif_channel_name),
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = ctx.getString(R.string.notif_channel_desc)
            setShowBadge(false)
        }
        nm.createNotificationChannel(ch)
    }

    fun buildOngoing(ctx: Context, statusLine: String? = null): Notification {
        ensureChannel(ctx)
        val open = PendingIntent.getActivity(
            ctx, 0, Intent(ctx, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val syncPi = PendingIntent.getService(
            ctx, 1,
            Intent(ctx, KeepAliveService::class.java).setAction(KeepAliveService.ACTION_SYNC),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val last = Prefs.lastSync(ctx)
        val lastTxt = if (last == 0L) ctx.getString(R.string.notif_never)
        else SimpleDateFormat("HH:mm", Locale.getDefault()).format(Date(last))
        val text = statusLine ?: ctx.getString(R.string.notif_body, lastTxt)
        return NotificationCompat.Builder(ctx, CHANNEL_ID)
            .setContentTitle(ctx.getString(R.string.notif_title))
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setSmallIcon(android.R.drawable.ic_popup_sync)
            .setContentIntent(open)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .addAction(0, ctx.getString(R.string.notif_action_sync), syncPi)
            .build()
    }

    fun update(ctx: Context, statusLine: String? = null) {
        ensureChannel(ctx)
        val nm = ctx.getSystemService(NotificationManager::class.java) ?: return
        nm.notify(NOTIF_ID, buildOngoing(ctx, statusLine))
    }
}
