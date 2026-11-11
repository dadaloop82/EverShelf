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
    private const val KEY_LAST_STEP   = "last_step"
    private const val KEY_LAST_STEP_TS = "last_step_ts"

    private val executor = Executors.newSingleThreadExecutor()

    // Fingerprints already sent in this process to avoid flooding
    private val sentFingerprints = mutableSetOf<String>()

    private var serverBaseUrl: String = ""
    private var appVersion: String = ""
    private var deviceInfo: String = ""
    private lateinit var appContext: Context

    /**
     * Leave a breadcrumb of the step the app is in.
     *
     * Written to SharedPreferences **before** the step runs, so it survives anything: a
     * `SIGKILL` from the system (which is what the wall recorded at the kiosk-mode transition —
     * `killed-signal(2) status=9`, no Java trace, no tombstone, no renderer report) takes the
     * console, the buffered HTTP queue and the pending report with it, but not a value on disk.
     * The next start reports it (`reportLastRun()`) and the wall finally names the step it died in.
     *
     * Cheap and silent: one `apply()` per call, no I/O on the main thread beyond that.
     */
    fun step(name: String) {
        if (!::appContext.isInitialized) return
        try {
            appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit()
                .putString(KEY_LAST_STEP, name.take(80))
                .putLong(KEY_LAST_STEP_TS, System.currentTimeMillis())
                .apply()
        } catch (_: Exception) {}
    }

    /**
     * At boot: if the previous run never stopped cleanly, say which step it was in.
     *
     * Must be called **before** `detectPreviousCrash()`, which marks this launch as running.
     */
    private fun reportLastRun() {
        try {
            val prefs = appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            val wasRunning = prefs.getBoolean(KEY_WAS_RUNNING, false)
            val step = prefs.getString(KEY_LAST_STEP, null) ?: return
            if (!wasRunning || step == "clean") return
            val ts = prefs.getLong(KEY_LAST_STEP_TS, 0L)
            val age = if (ts > 0) ((System.currentTimeMillis() - ts) / 1000) else -1
            reportAsync(
                type    = "last-run",
                message = "Previous run died after step '$step' (${age}s later)",
                stack   = "",
                context = mapOf(
                    "device" to deviceInfo,
                    "step"   to step,
                    "step_age_s" to age,
                    "note"   to "Breadcrumb written before the step; survives a SIGKILL"
                ),
                level   = "warn"
            )
        } catch (_: Exception) {}
    }

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

        // …and, if the previous run never got to stop cleanly, the step it was in
        reportLastRun()

        // Detect ANR / OOM / native crashes from the previous run
        detectPreviousCrash()

        // …and say, every boot, how the last runs really ended — numbered reasons included.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) reportExitHistory()

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
                // Only a report that really reached the server may clear the pending entry.
                // Clearing it after a failed POST is how a `SecurityException` inside the BLE
                // gateway left the wall with no trace at all: the crash was saved, the POST
                // failed, the file was wiped, and the next launch had nothing to re-send.
                if (reportSync(type, message, stack, ctx)) clearPendingCrash()
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
        step("clean")
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
     * @param level `info` / `warn` / `error` — carried in the payload so the server files the line
     *   in `data/logs/kiosk-YYYYMMDD.log` under the right severity. A renderer that was killed is a
     *   `warn`; a JS exception is an `error`; a `[CAM]`/`[SHELFY]` trace from the page is `info`.
     */
    fun reportMessage(
        type: String,
        message: String,
        extra: Map<String, Any?> = emptyMap(),
        forceReport: Boolean = false,
        level: String = "info"
    ) {
        val ctx = mutableMapOf<String, Any?>("device" to deviceInfo)
        ctx.putAll(extra)
        reportAsync(type = type, message = message, stack = "", context = ctx, force = forceReport, level = level)
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
                ApplicationExitInfo.REASON_LOW_MEMORY,
                // A process killed by a SIGNAL leaves no Java trace and no tombstone in this API,
                // and filtering it out is exactly why the wall's death on the camera-permission
                // grant looked like "the app just disappeared": three reports, no `index.html`,
                // and nothing in the server log that said why.
                ApplicationExitInfo.REASON_SIGNALED
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
                    ApplicationExitInfo.REASON_SIGNALED     -> "killed-signal"
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

    /**
     * One line per boot describing how the last few runs really ended, whatever the reason.
     *
     * `detectPreviousCrash()` only speaks about the deaths it can name, and a wall that dies
     * **before it loads the page** has no other witness: `renderer-crashed` needs a live Activity,
     * the UncaughtExceptionHandler needs a Java throw, and the page's own log needs a page. That
     * is exactly the hole a fresh install fell into (three `report_error` POSTs, no `index.html`,
     * nothing in the log that said why), so the reason number, the timestamp, the status and
     * Android's own description of the last five exits go to the server on every boot.
     */
    @RequiresApi(Build.VERSION_CODES.R)
    private fun reportExitHistory() {
        try {
            val am = appContext.getSystemService(ActivityManager::class.java) ?: return
            val exits = am.getHistoricalProcessExitReasons(null, 0, 5)
            if (exits.isEmpty()) return
            val fmt = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
            val line = exits.joinToString(" | ") { e ->
                val name = when (e.reason) {
                    ApplicationExitInfo.REASON_CRASH           -> "crash-java"
                    ApplicationExitInfo.REASON_CRASH_NATIVE    -> "crash-native"
                    ApplicationExitInfo.REASON_ANR             -> "anr"
                    ApplicationExitInfo.REASON_LOW_MEMORY      -> "oom-kill"
                    ApplicationExitInfo.REASON_SIGNALED        -> "killed-signal"
                    ApplicationExitInfo.REASON_USER_REQUESTED  -> "user-requested"
                    ApplicationExitInfo.REASON_OTHER           -> "other"
                    else                                       -> "reason-${e.reason}"
                }
                val desc = (e.description ?: "").replace("\n", " ").take(90)
                val proc = if (e.processName.isNullOrEmpty()) "" else " proc=${e.processName}"
                "$name(${e.reason}) at=${fmt.format(Date(e.timestamp))} status=${e.status}" +
                    (if (desc.isNotEmpty()) " ($desc)" else "") + proc
            }
            reportAsync(
                type    = "exit-history",
                message = "Last exits: $line",
                stack   = "",
                context = mapOf("device" to deviceInfo, "count" to exits.size),
                level   = "info"
            )
        } catch (_: Exception) {}
    }

    private fun fingerprint(type: String, message: String): String {
        val key = "$type:${message.take(120)}"
        return key.hashCode().toString(16)
    }

    private fun reportAsync(type: String, message: String, stack: String, context: Map<String, Any?>, force: Boolean = false, level: String = "error") {
        val fp = fingerprint(type, message)
        if (!force) {
            synchronized(sentFingerprints) {
                if (!sentFingerprints.add(fp)) return // already reported this session
            }
        } else {
            synchronized(sentFingerprints) { sentFingerprints.add(fp) }
        }
        executor.execute { val ignored = doPost(type, message, stack, context, level) }
    }

    /** Synchronous variant used only in the UncaughtExceptionHandler (already off main thread). */
    private fun reportSync(type: String, message: String, stack: String, context: Map<String, Any?>, level: String = "error"): Boolean {
        val fp = fingerprint(type, message)
        synchronized(sentFingerprints) { sentFingerprints.add(fp) }
        return doPost(type, message, stack, context, level)
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
                // Cleared only when the server really took it. It used to be cleared BEFORE the
                // POST, so a crash that could not be delivered (a LAN that was not up yet, the
                // 8 s timeout on a wall that had just died) was wiped and never re-sent — the
                // kiosk's own report of its own crash was the one thing we never got.
                if (doPost("$type-survived", message, stack, ctx)) clearPendingCrash()
            } catch (_: Exception) {}
        }
    }

    private fun doPost(type: String, message: String, stack: String, context: Map<String, Any?>, level: String = "error"): Boolean {
        val url = serverBaseUrl.ifEmpty { return false }
        val endpoint = "$url/api/?action=report_error"
        try {
            val ctxJson = JSONObject()
            context.forEach { (k, v) -> ctxJson.put(k, v) }

            val payload = JSONObject().apply {
                put("source",     "kiosk")
                put("type",       type)
                put("level",      level)   // info / warn / error → data/logs/kiosk-<day>.log
                put("message",    message)
                put("stack",      stack)
                put("context",    ctxJson)
                put("version",    appVersion)
                put("user_agent", "EverShelf-Kiosk/$appVersion (Android ${Build.VERSION.RELEASE}; ${Build.MODEL})")
                put("url",        url)
                put("ts",         SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date()))
            }

            // A LAN install answers on a self-signed certificate. A plain `openConnection()`
            // throws SSLHandshakeException here — silently, because this function must never
            // throw — which is why the server log has NEVER received a kiosk report even
            // though the app has been sending them: trust it, exactly like every other call
            // this app makes (openTrustedConnection()).
            val raw = URL(endpoint).openConnection()
            if (raw is javax.net.ssl.HttpsURLConnection) {
                val trustAll = arrayOf<javax.net.ssl.TrustManager>(object : javax.net.ssl.X509TrustManager {
                    override fun checkClientTrusted(c: Array<java.security.cert.X509Certificate>?, t: String?) {}
                    override fun checkServerTrusted(c: Array<java.security.cert.X509Certificate>?, t: String?) {}
                    override fun getAcceptedIssuers(): Array<java.security.cert.X509Certificate> = arrayOf()
                })
                val sc = javax.net.ssl.SSLContext.getInstance("TLS")
                sc.init(null, trustAll, java.security.SecureRandom())
                raw.sslSocketFactory = sc.socketFactory
                raw.hostnameVerifier = javax.net.ssl.HostnameVerifier { _, _ -> true }
            }
            val conn = raw as HttpURLConnection
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
            return responseCode in 200..299
        } catch (e: Exception) {
            // Never rethrow from the error reporter itself
            Log.w(TAG, "Failed to report error '$type': ${e.message}")
            return false
        }
    }
}
