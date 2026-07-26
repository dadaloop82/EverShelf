package it.dadaloop.evershelf.health

import android.Manifest
import android.app.Activity
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat

object BatteryHelper {
    const val REQ_POST_NOTIFICATIONS = 4101

    fun isIgnoringBatteryOptimizations(ctx: Context): Boolean {
        val pm = ctx.getSystemService(Context.POWER_SERVICE) as PowerManager
        return pm.isIgnoringBatteryOptimizations(ctx.packageName)
    }

    /** Opens system dialog asking to disable battery optimizations for this app. */
    fun requestIgnoreBatteryOptimizations(activity: Activity) {
        if (isIgnoringBatteryOptimizations(activity)) return
        try {
            val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                data = Uri.parse("package:${activity.packageName}")
            }
            activity.startActivity(intent)
        } catch (_: Exception) {
            try {
                activity.startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
            } catch (_: Exception) { /* OEM without settings page */ }
        }
    }

    fun needsNotificationPermission(ctx: Context): Boolean {
        if (Build.VERSION.SDK_INT < 33) return false
        return ContextCompat.checkSelfPermission(ctx, Manifest.permission.POST_NOTIFICATIONS) !=
            PackageManager.PERMISSION_GRANTED
    }

    fun requestNotificationPermission(activity: Activity) {
        if (!needsNotificationPermission(activity)) return
        ActivityCompat.requestPermissions(
            activity,
            arrayOf(Manifest.permission.POST_NOTIFICATIONS),
            REQ_POST_NOTIFICATIONS
        )
    }

    /** Notifications + battery exemption + start sticky keep-alive. Call after pairing. */
    fun ensureBackgroundSurvival(activity: Activity) {
        requestNotificationPermission(activity)
        requestIgnoreBatteryOptimizations(activity)
        KeepAliveService.start(activity)
    }
}
