package it.dadaloop.evershelf.health

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities

/**
 * The gate that makes "send only when EverShelf answers" true.
 *
 * Having internet is NOT the question: a phone in the street has internet and no route to a
 * LAN server (or to the VPN that reaches it). Every upload therefore starts with a real
 * request to the configured server, and this object remembers the last answer for a minute so
 * a pass does not probe twice.
 */
object ServerReachability {
    private const val TTL_MS = 60_000L

    @Volatile private var lastCheckedAt = 0L
    @Volatile private var lastResult = false

    fun markReachable() {
        lastCheckedAt = System.currentTimeMillis()
        lastResult = true
    }

    fun markUnreachable() {
        lastCheckedAt = System.currentTimeMillis()
        lastResult = false
    }

    /** Last known verdict, or null when nothing was probed in the last minute. */
    fun cached(): Boolean? {
        if (lastCheckedAt == 0L) return null
        return if (System.currentTimeMillis() - lastCheckedAt < TTL_MS) lastResult else null
    }

    suspend fun check(ctx: Context, force: Boolean = false): Boolean {
        if (!force) {
            cached()?.let { return it }
        }
        if (!Prefs.isConfigured(ctx)) {
            markUnreachable()
            return false
        }
        val ok = EverShelfClient.probe(Prefs.url(ctx))
        if (ok) markReachable() else markUnreachable()
        Logs.info(ctx, "reachability", "health_bridge_hello -> $ok (${Prefs.url(ctx)})")
        return ok
    }

    /** The optional Wi-Fi-only guard; the server probe above always decides the upload. */
    fun onWifi(ctx: Context): Boolean {
        return try {
            val cm = ctx.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            val net = cm.activeNetwork ?: return false
            val caps = cm.getNetworkCapabilities(net) ?: return false
            caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) ||
                caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET)
        } catch (_: Throwable) {
            true
        }
    }
}
