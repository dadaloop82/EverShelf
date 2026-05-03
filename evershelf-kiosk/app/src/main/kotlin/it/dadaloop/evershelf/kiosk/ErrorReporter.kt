package it.dadaloop.evershelf.kiosk

import android.content.Context
import android.os.Build
import android.util.Log
import org.json.JSONObject
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.concurrent.Executors

/**
 * Centralized error reporter for EverShelf Kiosk.
 *
 * Sends structured JSON payloads to the EverShelf backend
 * (POST /api/?action=report_error) which in turn creates or
 * updates a GitHub Issue automatically.
 *
 * Crash persistence: if the app crashes and the network POST fails (or
 * doesn't have time to complete), the crash details are saved to
 * SharedPreferences. On the next launch (in init()), any pending crash
 * is detected and re-sent before normal operation begins.
 *
 * Usage:
 *   // In Application or Activity onCreate:
 *   ErrorReporter.init(this, prefs.getString("evershelf_url", "")!!)
 *
 *   // To report a caught exception:
 *   ErrorReporter.report(e, "myMethod", mapOf("extra" to "data"))
 *
 *   // To report a non-exception event:
 *   ErrorReporter.reportMessage("webview-crash", "WebView died unexpectedly")
 */
object ErrorReporter {

    private const val TAG = "EverShelfErrorReporter"

    // SharedPreferences for crash persistence
    private const val PREFS_NAME  = "evershelf_kiosk_errors"
    private const val KEY_PENDING = "pending_crash_json"

    private val executor = Executors.newSingleThreadExecutor()

    // Fingerprints already sent in this process to avoid flooding
    private val sentFingerprints = mutableSetOf<String>()

    private var serverBaseUrl: String = ""
    private var appVersion: String = ""
    private var deviceInfo: String = ""
    private lateinit var appContext: Context

    /**
     * Call once (e.g. in KioskActivity.onCreate) before reporting any errors.
     * @param context   Application or Activity context.
     * @param baseUrl   The EverShelf server URL, e.g. "http://192.168.1.10:8080"
     */
    fun init(context: Context, baseUrl: String) {
        appContext = context.applicationContext
        serverBaseUrl = baseUrl.trimEnd('/')
        try {
            val pi = context.packageManager.getPackageInfo(context.packageName, 0)
            appVersion = pi.versionName ?: "unknown"
        } catch (_: Exception) {}
        deviceInfo = "${Build.MANUFACTURER} ${Build.MODEL} (Android ${Build.VERSION.RELEASE})"

        // Send any crash that was saved to prefs during a previous session
        sendPendingCrash()

        // Install a global UncaughtExceptionHandler so ANY unhandled crash is reported
        val previousHandler = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            try {
                val type    = "uncaught-exception"
                val message = throwable.message ?: throwable.javaClass.simpleName
                val stack   = throwable.stackTraceToString()
                val ctx     = mapOf("thread" to thread.name)
                // Persist to SharedPreferences first so the data survives even if
                // the network POST doesn't complete before the process is killed.
                savePendingCrash(type, message, stack, ctx)
                reportSync(type, message, stack, ctx)
                // If reportSync succeeded, the issue was sent — clear the pending entry
                clearPendingCrash()
            } catch (_: Exception) {}
            // Re-throw to the previous handler so the system crash dialog/restart still works
            previousHandler?.uncaughtException(thread, throwable)
        }
    }

    /**
     * Report a caught [Throwable] asynchronously (does not block UI thread).
     */
    fun report(
        throwable: Throwable,
        location: String = "",
        extra: Map<String, Any?> = emptyMap()
    ) {
        val ctx = mutableMapOf<String, Any?>("device" to deviceInfo)
        if (location.isNotEmpty()) ctx["location"] = location
        ctx.putAll(extra)
        reportAsync(
            type    = "kiosk-exception",
            message = "${throwable.javaClass.simpleName}: ${throwable.message}",
            stack   = throwable.stackTraceToString(),
            context = ctx
        )
    }

    /**
     * Report a non-exception message (e.g. WebView page error, network failure).
     */
    fun reportMessage(
        type: String,
        message: String,
        extra: Map<String, Any?> = emptyMap()
    ) {
        val ctx = mutableMapOf<String, Any?>("device" to deviceInfo)
        ctx.putAll(extra)
        reportAsync(type = type, message = message, stack = "", context = ctx)
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private fun fingerprint(type: String, message: String): String {
        val key = "$type:${message.take(120)}"
        return key.hashCode().toString(16)
    }

    private fun reportAsync(type: String, message: String, stack: String, context: Map<String, Any?>) {
        val fp = fingerprint(type, message)
        synchronized(sentFingerprints) {
            if (!sentFingerprints.add(fp)) return // already reported this session
        }
        executor.execute { doPost(type, message, stack, context) }
    }

    /** Synchronous variant used only in the UncaughtExceptionHandler (already off main thread). */
    private fun reportSync(type: String, message: String, stack: String, context: Map<String, Any?>) {
        val fp = fingerprint(type, message)
        synchronized(sentFingerprints) { sentFingerprints.add(fp) }
        doPost(type, message, stack, context)
    }

    // ── Crash persistence helpers ─────────────────────────────────────────────

    private fun savePendingCrash(type: String, message: String, stack: String, context: Map<String, Any?>) {
        try {
            val ctxJson = JSONObject()
            context.forEach { (k, v) -> ctxJson.put(k, v) }
            val payload = JSONObject().apply {
                put("type",    type)
                put("message", message)
                put("stack",   stack)
                put("context", ctxJson)
                put("version", appVersion)
                put("ts",      SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date()))
            }
            appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
                .edit().putString(KEY_PENDING, payload.toString()).apply()
        } catch (_: Exception) {}
    }

    private fun clearPendingCrash() {
        appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit().remove(KEY_PENDING).apply()
    }

    /**
     * Called at the start of [init]: if there is an unsent crash from the
     * previous session, send it now and then clear the entry.
     */
    private fun sendPendingCrash() {
        val json = appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(KEY_PENDING, null) ?: return
        // Clear immediately so we don't re-send if THIS launch also crashes
        clearPendingCrash()
        executor.execute {
            try {
                val p       = JSONObject(json)
                val type    = p.optString("type", "uncaught-exception")
                val message = p.optString("message", "")
                val stack   = p.optString("stack", "")
                val savedTs = p.optString("ts", "")
                val ctxJson = p.optJSONObject("context") ?: JSONObject()
                val ctx     = mutableMapOf<String, Any?>("note" to "Sent on next launch after crash")
                if (savedTs.isNotEmpty()) ctx["crash_ts"] = savedTs
                ctxJson.keys().forEach { k -> ctx[k] = ctxJson.opt(k) }
                doPost("$type-survived", message, stack, ctx)
            } catch (_: Exception) {}
        }
    }

    private fun doPost(type: String, message: String, stack: String, context: Map<String, Any?>) {
        val url = serverBaseUrl.ifEmpty { return }
        val endpoint = "$url/api/?action=report_error"
        try {
            val ctxJson = JSONObject()
            context.forEach { (k, v) -> ctxJson.put(k, v) }

            val payload = JSONObject().apply {
                put("source",     "kiosk")
                put("type",       type)
                put("message",    message)
                put("stack",      stack)
                put("context",    ctxJson)
                put("version",    appVersion)
                put("user_agent", "EverShelf-Kiosk/$appVersion (Android ${Build.VERSION.RELEASE}; ${Build.MODEL})")
                put("url",        url)
                put("ts",         SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date()))
            }

            val conn = URL(endpoint).openConnection() as HttpURLConnection
            conn.requestMethod = "POST"
            conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            conn.setRequestProperty("Accept", "application/json")
            conn.doOutput = true
            conn.connectTimeout = 8000
            conn.readTimeout    = 8000

            OutputStreamWriter(conn.outputStream, Charsets.UTF_8).use { it.write(payload.toString()) }
            val responseCode = conn.responseCode
            conn.disconnect()

            Log.d(TAG, "Reported '$type' → HTTP $responseCode")
        } catch (e: Exception) {
            // Never rethrow from the error reporter itself
            Log.w(TAG, "Failed to report error '$type': ${e.message}")
        }
    }
}
