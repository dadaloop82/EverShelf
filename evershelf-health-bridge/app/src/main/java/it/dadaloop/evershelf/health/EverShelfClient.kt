package it.dadaloop.evershelf.health

import android.os.Build
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.BufferedReader
import java.io.File
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
 *
 * A LAN install answers on a self-signed certificate (and often on plain HTTP at a 192.168
 * address), so every call tries the configured scheme and its twin. What protects the APK
 * download is NOT TLS here: [Updater] proves the update is signed with the same key as the
 * installed app before it hands it to the installer.
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

    fun open(url: String, method: String = "GET", timeoutMs: Int = 8000): HttpURLConnection {
        val conn = URL(url).openConnection() as HttpURLConnection
        if (conn is HttpsURLConnection) {
            conn.sslSocketFactory = sslContext.socketFactory
            conn.hostnameVerifier = javax.net.ssl.HostnameVerifier { _, _ -> true }
        }
        conn.connectTimeout = timeoutMs
        conn.readTimeout = timeoutMs
        conn.requestMethod = method
        conn.setRequestProperty("Accept", "application/json")
        conn.setRequestProperty("User-Agent", "EverShelf-Health-Bridge/${AppInfo.versionName} (Android ${Build.VERSION.RELEASE}; ${Build.MODEL})")
        conn.instanceFollowRedirects = true
        return conn
    }

    /** Candidate base URLs: as given, then the http↔https twin for a LAN install. */
    private fun baseCandidates(baseUrl: String): List<String> {
        val root = Prefs.normalizeBaseUrl(baseUrl)
        if (root.isEmpty()) return emptyList()
        val alts = mutableListOf(root)
        when {
            root.startsWith("https://") -> alts += root.replaceFirst("https://", "http://")
            root.startsWith("http://") -> alts += root.replaceFirst("http://", "https://")
        }
        return alts.distinct()
    }

    private fun readBody(conn: HttpURLConnection): String {
        val stream = if (conn.responseCode in 200..299) conn.inputStream else conn.errorStream
        return BufferedReader(InputStreamReader(stream, StandardCharsets.UTF_8)).use { it.readText() }
    }

    /** Discovery + reachability: `health_bridge_hello` needs no token and no database. */
    suspend fun hello(baseUrl: String): Hello? = withContext(Dispatchers.IO) {
        for (root in baseCandidates(baseUrl)) {
            val endpoints = listOf(
                "${root}api/index.php?action=health_bridge_hello",
                "${root}api/index.php?action=ping",
            )
            for (ep in endpoints) {
                try {
                    val conn = open(ep, "GET", 5000)
                    if (conn.responseCode !in 200..299) continue
                    val json = runCatching { JSONObject(readBody(conn)) }.getOrNull() ?: continue
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
                    // try the next endpoint / scheme
                }
            }
        }
        null
    }

    /** True only when the SERVER answers — "has internet" is not the question. */
    suspend fun probe(baseUrl: String): Boolean = hello(baseUrl) != null

    suspend fun ingest(baseUrl: String, token: String, payload: JSONObject): Result<JSONObject> =
        withContext(Dispatchers.IO) {
            var lastError: Exception? = null
            for (root in baseCandidates(baseUrl)) {
                try {
                    val conn = open("${root}api/index.php?action=health_ingest", "POST", 15000).apply {
                        doOutput = true
                        setRequestProperty("Content-Type", "application/json; charset=utf-8")
                        setRequestProperty("X-Health-Token", token)
                        setRequestProperty("X-EverShelf-Request", "1")
                    }
                    OutputStreamWriter(conn.outputStream, StandardCharsets.UTF_8).use {
                        it.write(payload.toString())
                    }
                    val body = readBody(conn)
                    val json = runCatching { JSONObject(body.ifBlank { "{}" }) }
                        .getOrDefault(JSONObject())
                    if (conn.responseCode in 200..299 && json.optBoolean("success", false)) {
                        return@withContext Result.success(json.put("_resolved_base", root))
                    }
                    lastError = Exception(json.optString("error", "HTTP ${conn.responseCode}"))
                } catch (e: Exception) {
                    lastError = e
                }
            }
            Result.failure(lastError ?: Exception("unreachable"))
        }

    /**
     * `POST report_error` — the same door the kiosk uses. The backend writes the local log
     * and (when it is configured with a GitHub token) opens or comments on an issue.
     *
     * `version_guard_bypass` is deliberate: that guard compares against the WEBAPP release
     * tag, while the bridge has its own version line — without it every bridge report would
     * be silently dropped as "outdated_version".
     */
    suspend fun report(
        baseUrl: String,
        type: String,
        level: String,
        message: String,
        stack: String = "",
        context: Map<String, Any?> = emptyMap(),
    ): Boolean = withContext(Dispatchers.IO) {
        if (baseUrl.isBlank()) return@withContext false
        val ctxJson = JSONObject()
        context.forEach { (k, v) -> if (v != null) ctxJson.put(k, v) }
        ctxJson.put("version_guard_bypass", true)
        ctxJson.put("device", Build.MODEL)
        ctxJson.put("android", Build.VERSION.RELEASE)
        ctxJson.put("sdk", Build.VERSION.SDK_INT)
        val payload = JSONObject().apply {
            put("source", "health_bridge")
            put("type", type.take(80))
            put("level", level)
            put("message", message.take(500))
            put("stack", stack.take(4000))
            put("context", ctxJson)
            put("version", AppInfo.versionName)
            put("user_agent", "EverShelf-Health-Bridge/${AppInfo.versionName} (Android ${Build.VERSION.RELEASE}; ${Build.MODEL})")
            put("url", baseUrl)
        }
        for (root in baseCandidates(baseUrl)) {
            try {
                val conn = open("${root}api/index.php?action=report_error", "POST", 10000).apply {
                    doOutput = true
                    setRequestProperty("Content-Type", "application/json; charset=utf-8")
                    setRequestProperty("X-EverShelf-Request", "1")
                }
                OutputStreamWriter(conn.outputStream, StandardCharsets.UTF_8).use { it.write(payload.toString()) }
                val code = conn.responseCode
                readBody(conn)
                if (code in 200..299) return@withContext true
            } catch (_: Exception) {
                // try the next scheme
            }
        }
        false
    }

    suspend fun lanUpdate(baseUrl: String): JSONObject? = withContext(Dispatchers.IO) {
        for (root in baseCandidates(baseUrl)) {
            try {
                val conn = open("${root}api/index.php?action=health_bridge_update", "GET", 6000)
                if (conn.responseCode !in 200..299) continue
                val json = runCatching { JSONObject(readBody(conn)) }.getOrNull() ?: continue
                if (json.optBoolean("success", false)) return@withContext json
            } catch (_: Exception) { }
        }
        null
    }

    suspend fun githubLatest(): JSONObject? = withContext(Dispatchers.IO) {
        val url = "https://api.github.com/repos/${AppInfo.REPO_OWNER}/${AppInfo.REPO_NAME}" +
            "/releases/tags/${AppInfo.RELEASE_TAG}"
        try {
            val conn = open(url, "GET", 10000)
            conn.setRequestProperty("Accept", "application/vnd.github+json")
            if (conn.responseCode !in 200..299) return@withContext null
            runCatching { JSONObject(readBody(conn)) }.getOrNull()
        } catch (_: Exception) {
            null
        }
    }

    /** Streams a file to [dest]; [onProgress] receives 0..100 or -1 when the size is unknown. */
    suspend fun download(url: String, dest: File, onProgress: (Int) -> Unit = {}): Boolean =
        withContext(Dispatchers.IO) {
            try {
                val conn = open(url, "GET", 20000)
                conn.readTimeout = 60000
                val total = conn.contentLengthLong
                conn.inputStream.use { input ->
                    dest.outputStream().use { out ->
                        val buf = ByteArray(64 * 1024)
                        var bytes = 0L
                        var lastUi = 0L
                        while (true) {
                            val n = input.read(buf)
                            if (n <= 0) break
                            out.write(buf, 0, n)
                            bytes += n
                            val now = System.currentTimeMillis()
                            if (now - lastUi > 250) {
                                lastUi = now
                                onProgress(if (total > 0) ((bytes * 100) / total).toInt() else -1)
                            }
                        }
                    }
                }
                conn.disconnect()
                dest.length() > 0
            } catch (_: Exception) {
                runCatching { dest.delete() }
                false
            }
        }
}
