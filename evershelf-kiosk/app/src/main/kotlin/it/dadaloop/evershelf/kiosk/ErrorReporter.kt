package it.dadaloop.evershelf.kiosk

import android.app.ActivityManager
import android.app.ApplicationExitInfo
import android.content.Context
import android.os.Build
import android.util.Log
import androidx.annotation.RequiresApi
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
    private const val KEY_PENDING     = "pending_crash_json"
    private const val KEY_WAS_RUNNING = "was_running_dirty"
    private const val KEY_LAST_EXIT_TS = "last_reported_exit_ts"

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
        deviceInfo = buildString {
            val mfr   = Build.MANUFACTURER.takeIf { it.isNotBlank() && it != "unknown" }
                ?: Build.PRODUCT.takeIf { it.isNotBlank() && it != "unknown" }
                ?: Build.BOARD
            val model = Build.MODEL.takeIf { it.isNotBlank() && it != "unknown" }
                ?: Build.HARDWARE
            append("$mfr $model (Android ${Build.VERSION.RELEASE}/${Build.VERSION.SDK_INT})")
        }

        // Send any crash that was saved to prefs during a previous session
        sendPendingCrash()

        // Detect ANR / OOM / native crashes from the previous run
        detectPreviousCrash()

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
     * Call from Activity.onDestroy() on a *clean* exit (back-pressed, settings, shutdown).
     * Clears the dirty-launch sentinel so the next start does not report a false positive.
     */
    fun markCleanStop() {
        if (::appContext.isInitialized) {
            appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
                .edit().putBoolean(KEY_WAS_RUNNING, false).apply()
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
     * @param forceReport if true, bypasses the in-session dedup so retries are always sent.
     */
    fun reportMessage(
        type: String,
        message: String,
        extra: Map<String, Any?> = emptyMap(),
        forceReport: Boolean = false
    ) {
        val ctx = mutableMapOf<String, Any?>("device" to deviceInfo)
        ctx.putAll(extra)
        reportAsync(type = type, message = message, stack = "", context = ctx, force = forceReport)
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    /**
     * Detects whether the *previous* run of the app ended with a crash, ANR or OOM kill.
     *
     * On Android 11+ (API 30) we use [ActivityManager.getHistoricalProcessExitReasons] which
     * gives the exact reason and (for Java crashes) a stack trace.
     *
     * On Android 7–10 we use a "dirty-launch sentinel": a boolean in SharedPreferences that is
     * set to `true` on every start and `false` only when the activity is destroyed cleanly via
     * [markCleanStop]. If it is still `true` on the next start, the previous run was not clean.
     */
    private fun detectPreviousCrash() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            detectExitReasonApi30()
        } else {
            // API 24–29: dirty-launch sentinel
            val prefs = appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            if (prefs.getBoolean(KEY_WAS_RUNNING, false)) {
                reportAsync(
                    type    = "crash-sentinel",
                    message = "App was not cleanly shut down on previous run (ANR / OOM / native crash suspected).",
                    stack   = "",
                    context = mapOf(
                        "device" to deviceInfo,
                        "note"   to "Detected via dirty-launch sentinel (API ${Build.VERSION.SDK_INT})"
                    )
                )
            }
        }
        // Mark this launch as running — will be cleared by markCleanStop() on clean exit
        appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit().putBoolean(KEY_WAS_RUNNING, true).apply()
    }

    @RequiresApi(Build.VERSION_CODES.R)
    private fun detectExitReasonApi30() {
        try {
            val am = appContext.getSystemService(ActivityManager::class.java) ?: return
            // Check the last 5 exits; stop at the first we already reported
            val exits = am.getHistoricalProcessExitReasons(null, 0, 5)
            if (exits.isEmpty()) return

            val prefs         = appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            val lastReportedTs = prefs.getLong(KEY_LAST_EXIT_TS, 0L)

            val crashReasons = setOf(
                ApplicationExitInfo.REASON_CRASH,
                ApplicationExitInfo.REASON_CRASH_NATIVE,
                ApplicationExitInfo.REASON_ANR,
                ApplicationExitInfo.REASON_LOW_MEMORY
            )

            var newestTs = lastReportedTs
            for (exit in exits) {
                if (exit.timestamp <= lastReportedTs) continue     // already reported
                if (exit.reason !in crashReasons) continue

                val reasonName = when (exit.reason) {
                    ApplicationExitInfo.REASON_CRASH        -> "crash-java"
                    ApplicationExitInfo.REASON_CRASH_NATIVE -> "crash-native"
                    ApplicationExitInfo.REASON_ANR          -> "anr"
                    ApplicationExitInfo.REASON_LOW_MEMORY   -> "oom-kill"
                    else                                    -> "exit-${exit.reason}"
                }
                val msg = exit.description?.takeIf { it.isNotEmpty() }
                    ?: "${exit.processName ?: "app"} terminated (reason ${exit.reason})"

                // Java crashes include a tombstone trace — read up to 4KB
                var stack = ""
                try {
                    exit.traceInputStream?.bufferedReader()?.use { stack = it.readText().take(4000) }
                } catch (_: Exception) {}

                val ctx = mutableMapOf<String, Any?>(
                    "device"  to deviceInfo,
                    "reason"  to exit.reason,
                    "process" to (exit.processName ?: ""),
                    "crash_ts" to SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date(exit.timestamp)),
                    "note"    to "Detected via ApplicationExitInfo on restart (API ${Build.VERSION.SDK_INT})"
                )
                reportAsync(type = reasonName, message = msg, stack = stack, context = ctx)

                if (exit.timestamp > newestTs) newestTs = exit.timestamp
            }

            if (newestTs > lastReportedTs) {
                prefs.edit().putLong(KEY_LAST_EXIT_TS, newestTs).apply()
            }
        } catch (_: Exception) {}
    }

    private fun fingerprint(type: String, message: String): String {
        val key = "$type:${message.take(120)}"
        return key.hashCode().toString(16)
    }

    private fun reportAsync(type: String, message: String, stack: String, context: Map<String, Any?>, force: Boolean = false) {
        val fp = fingerprint(type, message)
        if (!force) {
            synchronized(sentFingerprints) {
                if (!sentFingerprints.add(fp)) return // already reported this session
            }
        } else {
            synchronized(sentFingerprints) { sentFingerprints.add(fp) }
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
            // The API's CSRF guard requires this header on every POST.
            conn.setRequestProperty("X-EverShelf-Request", "1")
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
