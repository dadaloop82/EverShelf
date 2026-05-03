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
    private val executor = Executors.newSingleThreadExecutor()

    // Fingerprints already sent in this process to avoid flooding
    private val sentFingerprints = mutableSetOf<String>()

    private var serverBaseUrl: String = ""
    private var appVersion: String = ""
    private var deviceInfo: String = ""

    /**
     * Call once (e.g. in KioskActivity.onCreate) before reporting any errors.
     * @param context   Application or Activity context.
     * @param baseUrl   The EverShelf server URL, e.g. "http://192.168.1.10:8080"
     */
    fun init(context: Context, baseUrl: String) {
        serverBaseUrl = baseUrl.trimEnd('/')
        try {
            val pi = context.packageManager.getPackageInfo(context.packageName, 0)
            appVersion = pi.versionName ?: "unknown"
        } catch (_: Exception) {}
        deviceInfo = "${Build.MANUFACTURER} ${Build.MODEL} (Android ${Build.VERSION.RELEASE})"

        // Install a global UncaughtExceptionHandler so ANY unhandled crash is reported
        val previousHandler = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            try {
                reportSync(
                    type    = "uncaught-exception",
                    message = throwable.message ?: throwable.javaClass.simpleName,
                    stack   = throwable.stackTraceToString(),
                    context = mapOf("thread" to thread.name)
                )
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
