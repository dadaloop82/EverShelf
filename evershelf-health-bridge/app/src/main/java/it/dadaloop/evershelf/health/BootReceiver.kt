package it.dadaloop.evershelf.health

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * After a reboot: WorkManager only.
 *
 * Never a foreground service here — Android 15 throws ForegroundServiceStartNotAllowedException
 * for a `dataSync` service started from BOOT_COMPLETED, and an exception in a receiver is a
 * crash the household reads as "it closed by itself".
 */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        val action = intent?.action ?: return
        if (action != Intent.ACTION_BOOT_COMPLETED && action != Intent.ACTION_MY_PACKAGE_REPLACED) return
        if (!Prefs.isConfigured(context)) return
        Logs.info(context, "boot", "$action — scheduling the periodic sync")
        SyncEngine.schedulePeriodic(context)
        SyncEngine.requestImmediate(context, delayMs = 60_000)
    }
}
