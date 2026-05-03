package it.dadaloop.evershelf.scalegate

import android.content.Context
import android.os.Build
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.concurrent.Executors

/**
 * Centralized error reporter for EverShelf Scale Gateway.
 *
 * Unlike the Kiosk (which relays errors through the EverShelf PHP backend),
 * the Scale Gateway has no knowledge of the EverShelf server URL, so it
 * calls the GitHub Issues REST API directly.
 *
 * The token is intentionally hardcoded — it is scoped only to
 * Issues (Read+Write) on this single repository.
 *
 * Usage:
 *   ErrorReporter.init(applicationContext)
 *   ErrorReporter.report(exception, "methodName", mapOf("extra" to "info"))
 *   ErrorReporter.reportMessage("ble-disconnect", "Scale disconnected after 3 retries")
 */
object ErrorReporter {

    private const val TAG = "ScaleGWErrorReporter"

    // ── XOR-obfuscated GitHub token (scoped: Issues R+W on dadaloop82/EverShelf) ──
    // Stored encoded so the literal token string never appears in source or git history.
    private const val GH_TOKEN_ENC = "23580718460c2c444031290243627e7971622b29035e2a647726407d194f61440b6e05246a0c067c79730e77114b774501730043433d1866682225511b5443417170444443142941673c4046086c05737363293e7821006e470a466a1d"
    private const val GH_TOKEN_KEY = "D1sp3ns4!Ev3r#26"
    private const val GH_REPO  = "dadaloop82/EverShelf"

    private var _ghTokenCache: String? = null
    private fun ghToken(): String {
        _ghTokenCache?.let { return it }
        val enc = GH_TOKEN_ENC.chunked(2).map { it.toInt(16).toByte() }.toByteArray()
        val key = GH_TOKEN_KEY
        val out = String(ByteArray(enc.size) { i -> (enc[i].toInt() xor key[i % key.length].code).toByte() })
        _ghTokenCache = out
        return out
    }

    // SharedPreferences key for pending (unsent) crash reports
    private const val PREFS_NAME    = "evershelf_scalegw_errors"
    private const val KEY_PENDING   = "pending_crash_json"

    private val executor = Executors.newSingleThreadExecutor()
    private val sentFingerprints = mutableSetOf<String>()

    private var appVersion: String = "unknown"
    private var deviceInfo: String = ""
    private lateinit var appContext: Context

    /**
     * Call once in MainActivity.onCreate() or Application.onCreate().
     */
    fun init(context: Context) {
        appContext = context.applicationContext
        deviceInfo = "${Build.MANUFACTURER} ${Build.MODEL} (Android ${Build.VERSION.RELEASE})"
        try {
            val pi = context.packageManager.getPackageInfo(context.packageName, 0)
            appVersion = pi.versionName ?: "unknown"
        } catch (_: Exception) {}

        // Send any crash report that was saved from the previous session
        sendPendingCrash()

        // Install global UncaughtExceptionHandler
        val previous = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            try {
                val crash = buildPayload(
                    type    = "uncaught-exception",
                    message = "${throwable.javaClass.simpleName}: ${throwable.message}",
                    stack   = throwable.stackTraceToString(),
                    context = mapOf("thread" to thread.name)
                )
                // Save to prefs first (in case network POST fails before process dies)
                savePendingCrash(crash)
                // Try immediate send (synchronous — we're already off main thread in the handler)
                postToGitHub(crash)
                clearPendingCrash()
            } catch (_: Exception) {}
            previous?.uncaughtException(thread, throwable)
        }
    }

    /** Report a caught [Throwable] asynchronously. */
    fun report(throwable: Throwable, location: String = "", extra: Map<String, Any?> = emptyMap()) {
        val ctx = mutableMapOf<String, Any?>("device" to deviceInfo)
        if (location.isNotEmpty()) ctx["location"] = location
        ctx.putAll(extra)
        enqueue(
            type    = "scale-exception",
            message = "${throwable.javaClass.simpleName}: ${throwable.message}",
            stack   = throwable.stackTraceToString(),
            context = ctx
        )
    }

    /** Report a non-exception event (e.g. BLE disconnect, WebSocket error). */
    fun reportMessage(type: String, message: String, extra: Map<String, Any?> = emptyMap()) {
        val ctx = mutableMapOf<String, Any?>("device" to deviceInfo)
        ctx.putAll(extra)
        enqueue(type = type, message = message, stack = "", context = ctx)
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private fun fingerprint(type: String, message: String) =
        "${type}:${message.take(120)}".hashCode().toString(16)

    private fun enqueue(type: String, message: String, stack: String, context: Map<String, Any?>) {
        val fp = fingerprint(type, message)
        synchronized(sentFingerprints) {
            if (!sentFingerprints.add(fp)) return
        }
        val payload = buildPayload(type, message, stack, context)
        executor.execute { postToGitHub(payload) }
    }

    private fun buildPayload(type: String, message: String, stack: String, context: Map<String, Any?>): JSONObject {
        val ctxJson = JSONObject()
        context.forEach { (k, v) -> ctxJson.put(k, v) }
        return JSONObject().apply {
            put("source",     "scale")
            put("type",       type)
            put("message",    message)
            put("stack",      stack)
            put("context",    ctxJson)
            put("version",    appVersion)
            put("user_agent", "EverShelf-ScaleGateway/$appVersion (Android ${Build.VERSION.RELEASE}; ${Build.MODEL})")
            put("ts",         SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date()))
        }
    }

    /** Persist crash payload to SharedPreferences so it survives a process kill. */
    private fun savePendingCrash(payload: JSONObject) {
        appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit().putString(KEY_PENDING, payload.toString()).apply()
    }

    private fun clearPendingCrash() {
        appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit().remove(KEY_PENDING).apply()
    }

    /** On startup, check if there's an unsent crash report from the previous session. */
    private fun sendPendingCrash() {
        val json = appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(KEY_PENDING, null) ?: return
        clearPendingCrash() // remove before sending to prevent re-sending on next crash
        executor.execute {
            try {
                val payload = JSONObject(json)
                // Tag it as a "survived-crash" so we know it was saved and retried
                payload.put("type", "uncaught-exception-survived")
                payload.put("note", "Sent on next launch after crash")
                postToGitHub(payload)
            } catch (_: Exception) {}
        }
    }

    /**
     * Create a GitHub Issue (or add a comment to an existing one with the same fingerprint).
     * Uses the GitHub Issues Search API to deduplicate.
     */
    private fun postToGitHub(payload: JSONObject) {
        val source  = payload.optString("source", "scale")
        val type    = payload.optString("type", "error")
        val message = payload.optString("message", "")
        val stack   = payload.optString("stack", "")
        val version = payload.optString("version", "")
        val ua      = payload.optString("user_agent", "")
        val ts      = payload.optString("ts", "")
        val ctxJson = payload.optJSONObject("context") ?: JSONObject()

        val fp = fingerprint(type, message)

        // ── 1. Search for existing open issue ──────────────────────────────
        val searchQ = "repo:$GH_REPO is:issue is:open label:auto-report \"fp:$fp\" in:body"
        val searchUrl = "https://api.github.com/search/issues?q=${java.net.URLEncoder.encode(searchQ, "UTF-8")}&per_page=1"
        val searchResult = ghGet(searchUrl) ?: JSONObject()
        val existingNumber = searchResult.optJSONArray("items")?.optJSONObject(0)?.optInt("number", 0)?.takeIf { it > 0 }

        // ── 2. Build body ─────────────────────────────────────────────────
        val ctxMd   = if (ctxJson.length() > 0) "\n**Context:**\n```json\n${ctxJson.toString(2)}\n```\n" else ""
        val stackMd = if (stack.isNotEmpty()) "\n**Stack trace:**\n```\n$stack\n```\n" else ""

        if (existingNumber != null) {
            // Comment on existing issue
            val body = "### 🔁 Recurrence — $ts\n**Source:** `$source` | **Type:** `$type`\n**UA:** `$ua`\n$ctxMd$stackMd\n---\n_fp:$fp_"
            ghPost("https://api.github.com/repos/$GH_REPO/issues/$existingNumber/comments", JSONObject().put("body", body))
        } else {
            // Create new issue
            val shortMsg = if (message.length > 70) "${message.take(70)}…" else message
            val title = "[SCALE] $shortMsg"
            val body = "## 🚨 Automatic Error Report\n\n**Source:** `$source`  \n**Type:** `$type`  \n**Reported at:** $ts  \n**UA:** `$ua`  \n**Version:** `$version`\n\n**Error message:**\n> $message\n$stackMd$ctxMd\n---\n<!-- auto-report fp:$fp -->\n_This issue was created automatically by EverShelf Scale Gateway error reporter. fp:`$fp`_"
            ghPost(
                "https://api.github.com/repos/$GH_REPO/issues",
                JSONObject()
                    .put("title", title)
                    .put("body", body)
                    .put("labels", JSONArray().put("auto-report").put("scale-error"))
            )
        }
    }

    private fun ghGet(url: String): JSONObject? = try {
        val conn = URL(url).openConnection() as HttpURLConnection
        conn.setRequestProperty("Authorization", "token ${ghToken()}")
        conn.setRequestProperty("Accept", "application/vnd.github+json")
        conn.setRequestProperty("X-GitHub-Api-Version", "2022-11-28")
        conn.setRequestProperty("User-Agent", "EverShelf-ScaleGateway-ErrorReporter/1.0")
        conn.connectTimeout = 8000
        conn.readTimeout = 8000
        val raw = BufferedReader(InputStreamReader(conn.inputStream)).readText()
        conn.disconnect()
        JSONObject(raw)
    } catch (e: Exception) { Log.w(TAG, "ghGet failed: ${e.message}"); null }

    private fun ghPost(url: String, payload: JSONObject): Int = try {
        val conn = URL(url).openConnection() as HttpURLConnection
        conn.requestMethod = "POST"
        conn.setRequestProperty("Authorization", "token ${ghToken()}")
        conn.setRequestProperty("Accept", "application/vnd.github+json")
        conn.setRequestProperty("X-GitHub-Api-Version", "2022-11-28")
        conn.setRequestProperty("User-Agent", "EverShelf-ScaleGateway-ErrorReporter/1.0")
        conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
        conn.doOutput = true
        conn.connectTimeout = 8000
        conn.readTimeout = 8000
        OutputStreamWriter(conn.outputStream, Charsets.UTF_8).use { it.write(payload.toString()) }
        val code = conn.responseCode
        conn.disconnect()
        Log.d(TAG, "ghPost $url → HTTP $code")
        code
    } catch (e: Exception) { Log.w(TAG, "ghPost failed: ${e.message}"); -1 }
}
