package it.dadaloop.evershelf.health

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller

/** The installer's verdict: logged, so "installation failed" is never a dead end. */
class InstallResultReceiver : BroadcastReceiver() {
    @Suppress("DEPRECATION")
    override fun onReceive(context: Context, intent: Intent?) {
        if (intent == null) return
        val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, Int.MIN_VALUE)
        val message = intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE) ?: ""
        when (status) {
            PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                val confirm = if (android.os.Build.VERSION.SDK_INT >= 33) {
                    intent.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java)
                } else {
                    intent.getParcelableExtra(Intent.EXTRA_INTENT)
                }
                if (confirm != null) {
                    confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    runCatching { context.startActivity(confirm) }
                }
            }
            PackageInstaller.STATUS_SUCCESS -> {
                Prefs.setPendingUpdateVersion(context, "")
                Logs.info(context, "update", "installed")
            }
            else -> {
                Logs.error(context, "update", "install status=$status message=$message")
                ErrorReporter.report(
                    context, "install_failure",
                    "PackageInstaller status=$status: $message",
                    mapOf("session" to intent.getIntExtra(EXTRA_SESSION, -1)),
                    level = "error"
                )
            }
        }
    }

    companion object {
        const val EXTRA_SESSION = "session_id"
    }
}
