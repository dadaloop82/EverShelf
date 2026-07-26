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

object EverShelfClient {

    data class Hello(
        val ok: Boolean,
        val name: String,
        val healthEnabled: Boolean,
        val version: String,
        val baseUrl: String,
    )

    suspend fun hello(baseUrl: String): Hello? = withContext(Dispatchers.IO) {
        val root = Prefs.normalizeBaseUrl(baseUrl)
        val endpoints = listOf(
            "${root}api/index.php?action=health_bridge_hello",
            "${root}index.php?action=health_bridge_hello",
        )
        for (ep in endpoints) {
            try {
                val conn = (URL(ep).openConnection() as HttpURLConnection).apply {
                    connectTimeout = 2500
                    readTimeout = 2500
                    requestMethod = "GET"
                    setRequestProperty("Accept", "application/json")
                }
                val code = conn.responseCode
                if (code !in 200..299) continue
                val body = conn.inputStream.bufferedReader().use { it.readText() }
                val json = JSONObject(body)
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
                // try next
            }
        }
        // Fallback: plain ping
        try {
            val ping = URL("${root}api/index.php?action=ping").openConnection() as HttpURLConnection
            ping.connectTimeout = 2000
            ping.readTimeout = 2000
            if (ping.responseCode in 200..299) {
                val body = ping.inputStream.bufferedReader().use { it.readText() }
                if (body.contains("\"ok\"")) {
                    return@withContext Hello(true, "EverShelf", true, "", root)
                }
            }
        } catch (_: Exception) {
        }
        null
    }

    suspend fun ingest(baseUrl: String, token: String, payload: JSONObject): Result<JSONObject> =
        withContext(Dispatchers.IO) {
            try {
                val root = Prefs.normalizeBaseUrl(baseUrl)
                val url = URL("${root}api/index.php?action=health_ingest")
                val conn = (url.openConnection() as HttpURLConnection).apply {
                    connectTimeout = 12000
                    readTimeout = 12000
                    requestMethod = "POST"
                    doOutput = true
                    setRequestProperty("Content-Type", "application/json; charset=utf-8")
                    setRequestProperty("Accept", "application/json")
                    setRequestProperty("X-Health-Token", token)
                    setRequestProperty("X-EverShelf-Request", "1")
                }
                OutputStreamWriter(conn.outputStream, StandardCharsets.UTF_8).use { it.write(payload.toString()) }
                val stream = if (conn.responseCode in 200..299) conn.inputStream else conn.errorStream
                val body = BufferedReader(InputStreamReader(stream, StandardCharsets.UTF_8)).use { it.readText() }
                val json = JSONObject(body.ifBlank { "{}" })
                if (conn.responseCode in 200..299 && json.optBoolean("success", false)) {
                    Result.success(json)
                } else {
                    Result.failure(Exception(json.optString("error", "HTTP ${conn.responseCode}")))
                }
            } catch (e: Exception) {
                Result.failure(e)
            }
        }
}
