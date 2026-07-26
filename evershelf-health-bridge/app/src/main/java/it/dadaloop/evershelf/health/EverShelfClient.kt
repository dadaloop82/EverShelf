package it.dadaloop.evershelf.health

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import java.nio.charset.StandardCharsets
import java.security.SecureRandom
import java.security.cert.X509Certificate
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager

/**
 * HTTP client for EverShelf.
 * Trusts LAN self-signed HTTPS (same approach as EverShelf Kiosk) —
 * otherwise hello/ingest fail on https://192.168.x.x with a home cert.
 */
object EverShelfClient {

    data class Hello(
        val ok: Boolean,
        val name: String,
        val healthEnabled: Boolean,
        val version: String,
        val baseUrl: String,
    )

    private val trustAllManager = object : X509TrustManager {
        override fun checkClientTrusted(chain: Array<X509Certificate>?, authType: String?) {}
        override fun checkServerTrusted(chain: Array<X509Certificate>?, authType: String?) {}
        override fun getAcceptedIssuers(): Array<X509Certificate> = arrayOf()
    }

    private val sslContext: SSLContext = SSLContext.getInstance("TLS").also {
        it.init(null, arrayOf<TrustManager>(trustAllManager), SecureRandom())
    }

    private fun open(url: String, method: String = "GET", timeoutMs: Int = 8000): HttpURLConnection {
        val conn = URL(url).openConnection() as HttpURLConnection
        if (conn is HttpsURLConnection) {
            conn.sslSocketFactory = sslContext.socketFactory
            conn.hostnameVerifier = javax.net.ssl.HostnameVerifier { _, _ -> true }
        }
        conn.connectTimeout = timeoutMs
        conn.readTimeout = timeoutMs
        conn.requestMethod = method
        conn.setRequestProperty("Accept", "application/json")
        conn.instanceFollowRedirects = true
        return conn
    }

    /** Candidate base URLs: as given, then http↔https swap for LAN. */
    private fun baseCandidates(baseUrl: String): List<String> {
        val root = Prefs.normalizeBaseUrl(baseUrl)
        val alts = mutableListOf(root)
        when {
            root.startsWith("https://") -> alts += root.replaceFirst("https://", "http://")
            root.startsWith("http://") -> alts += root.replaceFirst("http://", "https://")
        }
        return alts.distinct()
    }

    suspend fun hello(baseUrl: String): Hello? = withContext(Dispatchers.IO) {
        for (root in baseCandidates(baseUrl)) {
            val endpoints = listOf(
                "${root}api/index.php?action=health_bridge_hello",
                "${root}api/index.php?action=ping",
            )
            for (ep in endpoints) {
                try {
                    val conn = open(ep, "GET", 5000)
                    val code = conn.responseCode
                    if (code !in 200..299) continue
                    val body = conn.inputStream.bufferedReader().use { it.readText() }
                    val json = try { JSONObject(body) } catch (_: Exception) { continue }
                    if (json.optBoolean("ok") || json.optString("service") == "evershelf") {
                        return@withContext Hello(
                            ok = true,
                            name = json.optString("name", "EverShelf"),
                            healthEnabled = json.optBoolean("health_enabled", true),
                            version = json.optString("version", ""),
                            baseUrl = root,
                        )
                    }
                } catch (_: Exception) {
                    // try next endpoint / scheme
                }
            }
        }
        null
    }

    suspend fun ingest(baseUrl: String, token: String, payload: JSONObject): Result<JSONObject> =
        withContext(Dispatchers.IO) {
            var lastError: Exception? = null
            for (root in baseCandidates(baseUrl)) {
                try {
                    val url = "${root}api/index.php?action=health_ingest"
                    val conn = open(url, "POST", 15000).apply {
                        doOutput = true
                        setRequestProperty("Content-Type", "application/json; charset=utf-8")
                        setRequestProperty("X-Health-Token", token)
                        setRequestProperty("X-EverShelf-Request", "1")
                    }
                    OutputStreamWriter(conn.outputStream, StandardCharsets.UTF_8).use { it.write(payload.toString()) }
                    val stream = if (conn.responseCode in 200..299) conn.inputStream else conn.errorStream
                    val body = BufferedReader(InputStreamReader(stream, StandardCharsets.UTF_8)).use { it.readText() }
                    val json = JSONObject(body.ifBlank { "{}" })
                    if (conn.responseCode in 200..299 && json.optBoolean("success", false)) {
                        // Persist working scheme if we fell back http↔https
                        return@withContext Result.success(json.put("_resolved_base", root))
                    }
                    lastError = Exception(json.optString("error", "HTTP ${conn.responseCode}"))
                } catch (e: Exception) {
                    lastError = e
                }
            }
            Result.failure(lastError ?: Exception("unreachable"))
        }
}
