package it.dadaloop.evershelf.health

import android.content.Context
import android.net.ConnectivityManager
import android.net.LinkProperties
import android.net.wifi.WifiManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.withContext
import java.net.Inet4Address
import java.nio.ByteBuffer
import java.nio.ByteOrder

object DiscoveryScanner {

    data class Found(val url: String, val name: String, val version: String)

    suspend fun scan(ctx: Context, onProgress: ((String) -> Unit)? = null): List<Found> =
        withContext(Dispatchers.IO) {
            val subnet = detectSubnet(ctx) ?: return@withContext emptyList()
            onProgress?.invoke("Scansione $subnet.0/24…")
            val paths = listOf("/dispensa/", "/")
            val schemes = listOf("https", "http")
            val found = mutableListOf<Found>()
            val seen = mutableSetOf<String>()

            coroutineScope {
                val jobs = (1..254).map { host ->
                    async {
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
                                return@async
                            }
                        }
                    }
                }
                jobs.awaitAll()
            }
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
        } catch (_: Exception) {
        }
        return wifiFallback(ctx)
    }

    @Suppress("DEPRECATION")
    private fun wifiFallback(ctx: Context): String? {
        return try {
            val wm = ctx.applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
            val ip = wm.connectionInfo.ipAddress
            if (ip == 0) return null
            val bytes = ByteBuffer.allocate(4).order(ByteOrder.LITTLE_ENDIAN).putInt(ip).array()
            "${bytes[0].toInt() and 0xff}.${bytes[1].toInt() and 0xff}.${bytes[2].toInt() and 0xff}"
        } catch (_: Exception) {
            null
        }
    }
}
