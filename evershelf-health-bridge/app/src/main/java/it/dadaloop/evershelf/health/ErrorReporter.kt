package it.dadaloop.evershelf.health

import android.content.Context
import android.os.Build
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import java.util.concurrent.Executors

/**
 * Crashes and failures, persisted before anything else happens.
 *
 * The old app crashed "for no reason" and left nothing behind: a `SIGKILL` from Android takes
 * the console with it. So a crash is written to SharedPreferences FIRST (cheap, synchronous),
 * sent on the next start when the server answers, and every report also lands in the local log
 * ([Logs]) so the Info panel can show it even offline.
 */
object ErrorReporter {
    private const val PREFS = "evershelf_health_errors"
    private const val KEY_PENDING = "pending_crash"
    private const val KEY_LAST_SENT = "last_sent_at"

    private val executor = Executors.newSingleThreadExecutor()

    fun init(ctx: Context) {
        val app = ctx.applicationContext
        val previous = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, error ->
            try {
                savePending(app, thread, error)
            } catch (_: Throwable) { }
            previous?.uncaughtException(thread, error)
                ?: android.os.Process.killProcess(android.os.Process.myPid())
        }
        sendPending(app)
    }

    private fun prefs(ctx: Context) = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun savePending(ctx: Context, thread: Thread, error: Throwable) {
        val payload = JSONObject().apply {
            put("type", "crash")
            put("message", "${error.javaClass.name}: ${error.message ?: ""}")
            put("stack", error.stackTraceToString())
            put("thread", thread.name)
            put("version", AppInfo.versionName)
            put("at", Logs.ts())
        }
        prefs(ctx).edit().putString(KEY_PENDING, payload.toString()).commit()
        Logs.error(ctx, "crash", error)
    }

    /** Sends a crash left by the previous run, if the server can be reached. */
    fun sendPending(ctx: Context) {
        val raw = prefs(ctx).getString(KEY_PENDING, "") ?: ""
        if (raw.isBlank()) return
        executor.execute {
            try {
                val json = JSONObject(raw)
                val ok = runBlocking {
                    EverShelfClient.report(
                        baseUrl = Prefs.url(ctx),
                        type = json.optString("type", "crash"),
                        level = "error",
                        message = json.optString("message"),
                        stack = json.optString("stack"),
                        context = mapOf(
                            "previous_run" to true,
                            "thread" to json.optString("thread"),
                            "at" to json.optString("at"),
                        ),
                    )
                }
                Logs.info(ctx, "report", "pending crash sent=$ok")
                if (ok) {
                    prefs(ctx).edit().remove(KEY_PENDING)
                        .putLong(KEY_LAST_SENT, System.currentTimeMillis()).apply()
                }
            } catch (e: Throwable) {
                Logs.warn(ctx, "report", "pending crash could not be sent: ${e.message}")
            }
        }
    }

    /** Fire-and-forget report of a caught failure. Never throws. */
    fun report(
        ctx: Context,
        type: String,
        message: String,
        context: Map<String, Any?> = emptyMap(),
        level: String = "warn",
        stack: String = "",
    ) {
        val app = ctx.applicationContext
        Logs.write(app, level, type, message)
        if (!Prefs.autoReport(app)) return
        executor.execute {
            try {
                val extra = HashMap<String, Any?>(context)
                extra["pending_days"] = HealthDb(app).pendingCount()
                extra["paired"] = Prefs.isConfigured(app)
                runBlocking {
                    EverShelfClient.report(Prefs.url(app), type, level, message, stack, extra)
                }
            } catch (_: Throwable) { }
        }
    }

    /** Blocking variant for the Info panel's "Send a report now" button. */
    fun reportNow(ctx: Context, message: String): Boolean {
        val app = ctx.applicationContext
        Logs.warn(app, "manual_report", message)
        if (!Prefs.isConfigured(app)) return false
        return runBlocking {
            EverShelfClient.report(
                baseUrl = Prefs.url(app),
                type = "manual_report",
                level = "warn",
                message = message,
                context = mapOf(
                    "pending_days" to HealthDb(app).pendingCount(),
                    "days_stored" to HealthDb(app).dayCount(),
                    "android" to Build.VERSION.RELEASE,
                    "device" to Build.MODEL,
                ),
            )
        }
    }
}
