package it.dadaloop.evershelf.health

import android.content.Context
import android.content.Intent
import android.util.Log
import androidx.core.content.FileProvider
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * The bridge's own log: one file on the phone, readable from the Info panel and shirable
 * with the share sheet. It is the evidence a crash report needs, and it exists even when
 * the server is unreachable (which is exactly when a report cannot leave the phone).
 */
object Logs {
    private const val TAG = "EverShelfHealth"
    private const val FILE_NAME = "health-bridge.log"
    private const val MAX_BYTES = 512 * 1024

    @Synchronized
    fun write(ctx: Context, level: String, tag: String, message: String) {
        val line = "${ts()} [${level.uppercase(Locale.US)}] [$tag] $message\n"
        try {
            val f = file(ctx)
            if (f.exists() && f.length() > MAX_BYTES) {
                val old = File(f.parentFile, "$FILE_NAME.1")
                if (old.exists()) old.delete()
                f.renameTo(old)
            }
            f.appendText(line)
        } catch (_: Throwable) {
            // A logger that throws is worse than a lost line.
        }
        when (level) {
            "error" -> Log.e(TAG, "[$tag] $message")
            "warn" -> Log.w(TAG, "[$tag] $message")
            else -> Log.i(TAG, "[$tag] $message")
        }
    }

    fun info(ctx: Context, tag: String, message: String) = write(ctx, "info", tag, message)
    fun warn(ctx: Context, tag: String, message: String) = write(ctx, "warn", tag, message)
    fun error(ctx: Context, tag: String, message: String) = write(ctx, "error", tag, message)

    fun error(ctx: Context, tag: String, error: Throwable) =
        write(ctx, "error", tag, "${error.javaClass.simpleName}: ${error.message}\n" + error.stackTraceToString())

    fun file(ctx: Context): File = File(ctx.filesDir, FILE_NAME)

    fun tail(ctx: Context, maxChars: Int = 20000): String {
        return try {
            val f = file(ctx)
            if (!f.exists()) return ""
            val text = f.readText()
            if (text.length <= maxChars) text else text.takeLast(maxChars)
        } catch (_: Throwable) {
            ""
        }
    }

    fun clear(ctx: Context) {
        try {
            file(ctx).delete()
            File(ctx.filesDir, "$FILE_NAME.1").delete()
        } catch (_: Throwable) { }
    }

    /** Share sheet for the log file (the household sends it wherever they want). */
    fun share(ctx: Context) {
        try {
            val f = file(ctx)
            if (!f.exists()) f.writeText("EverShelf Health Bridge — empty log\n")
            val uri = FileProvider.getUriForFile(ctx, "${ctx.packageName}.provider", f)
            val send = Intent(Intent.ACTION_SEND).apply {
                type = "text/plain"
                putExtra(Intent.EXTRA_SUBJECT, ctx.getString(R.string.log_share_title))
                putExtra(Intent.EXTRA_STREAM, uri)
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            }
            ctx.startActivity(Intent.createChooser(send, ctx.getString(R.string.log_share_title)))
        } catch (e: Throwable) {
            warn(ctx, "logs", "share failed: ${e.message}")
        }
    }

    fun ts(): String = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date())
}
