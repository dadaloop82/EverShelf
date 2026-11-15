package it.dadaloop.evershelf.health

import android.content.Context
import android.net.ConnectivityManager
import android.net.LinkProperties
import android.net.wifi.WifiManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.sync.Semaphore
import kotlinx.coroutines.sync.withPermit
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import java.net.Inet4Address
import java.nio.ByteBuffer
import java.nio.ByteOrder

/**
 * Finds an EverShelf on the local network for the pairing step.
 *
 * 254 hosts × 2 schemes × 2 paths = 1016 requests, and the old version fired them ALL at once:
 * on a small phone that is an OOM or a frozen screen the household reads as a crash. Here at
 * most [PARALLEL] are in flight and the whole scan gives up after [TIMEOUT_MS].
 */
object DiscoveryScanner {

    data class Found(val url: String, val name: String, val version: String)

    private const val PARALLEL = 24
    private const val TIMEOUT_MS = 15_000L

    suspend fun scan(ctx: Context, onProgress: ((String) -> Unit)? = null): List<Found> =
        withContext(Dispatchers.IO) {
            val subnet = detectSubnet(ctx) ?: return@withContext emptyList()
            onProgress?.invoke("$subnet.0/24…")
            val gate = Semaphore(PARALLEL)
            val paths = listOf("/dispensa/", "/")
            val schemes = listOf("https", "http")
            val found = mutableListOf<Found>()
            val seen = mutableSetOf<String>()

            withTimeoutOrNull(TIMEOUT_MS) {
                coroutineScope {
                    (1..254).map { host ->
                        async {
                            gate.withPermit {
                                val ip = "$subnet.$host"
                                for (scheme in schemes) {
                                    for (path in paths) {
                                        val base = "$scheme://$ip$path"
                                        val hello = EverShelfClient.hello(base) ?: continue
                                        synchronized(found) {
                                            if (seen.add(hello.baseUrl)) {
                                                found.add(Found(hello.baseUrl, hello.name, hello.version))
                                            }
                                        }
                                        return@withPermit
                                    }
                                }
                            }
                        }
                    }.awaitAll()
                }
            }
            Logs.info(ctx, "discovery", "found ${found.size} host(s) on $subnet.0/24")
            found.sortedBy { it.url }
        }

    private fun detectSubnet(ctx: Context): String? {
        try {
            val cm = ctx.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            val net = cm.activeNetwork ?: return wifiFallback(ctx)
            val lp: LinkProperties = cm.getLinkProperties(net) ?: return wifiFallback(ctx)
            for (la in lp.linkAddresses) {
                val addr = la.address
                if (addr is Inet4Address && !addr.isLoopbackAddress) {
                    val parts = addr.hostAddress?.split(".") ?: continue
                    if (parts.size == 4) return "${parts[0]}.${parts[1]}.${parts[2]}"
                }
            }
        } catch (_: Throwable) { }
        return wifiFallback(ctx)
    }

    @Suppress("DEPRECATION")
    private fun wifiFallback(ctx: Context): String? = try {
        val wm = ctx.applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
        val ip = wm.connectionInfo.ipAddress
        if (ip == 0) null else {
            val bytes = ByteBuffer.allocate(4).order(ByteOrder.LITTLE_ENDIAN).putInt(ip).array()
            "${bytes[0].toInt() and 0xff}.${bytes[1].toInt() and 0xff}.${bytes[2].toInt() and 0xff}"
        }
    } catch (_: Throwable) {
        null
    }
}
