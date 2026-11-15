package it.dadaloop.evershelf.health

import android.app.Application
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities

/** Process-wide wiring: crash capture, the periodic pass, and "the network came back". */
class HealthBridgeApp : Application() {

    override fun onCreate() {
        super.onCreate()
        ErrorReporter.init(this)
        Logs.info(this, "app", "start version=${AppInfo.versionName} (${AppInfo.versionCode})")
        SyncEngine.schedulePeriodic(this)
        registerNetworkWatcher()
    }

    /**
     * A returned connection is the moment a queued day can finally leave. The sync itself
     * re-probes the server, so a phone on mobile data that cannot see EverShelf simply defers.
     */
    private fun registerNetworkWatcher() {
        try {
            val cm = getSystemService(ConnectivityManager::class.java) ?: return
            cm.registerDefaultNetworkCallback(object : ConnectivityManager.NetworkCallback() {
                override fun onAvailable(network: Network) {
                    if (!Prefs.isConfigured(this@HealthBridgeApp) || !Prefs.autoSync(this@HealthBridgeApp)) return
                    val caps = cm.getNetworkCapabilities(network) ?: return
                    if (!caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)) return
                    SyncEngine.requestImmediate(this@HealthBridgeApp, delayMs = 5_000)
                }
            })
        } catch (e: Throwable) {
            Logs.warn(this, "app", "network watcher unavailable: ${e.message}")
        }
    }
}
