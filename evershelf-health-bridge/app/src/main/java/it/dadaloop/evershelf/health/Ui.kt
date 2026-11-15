package it.dadaloop.evershelf.health

import android.content.Context
import android.graphics.Typeface
import android.text.method.ScrollingMovementMethod
import android.widget.ScrollView
import android.widget.TextView
import androidx.appcompat.app.AlertDialog

/** Small shared dialogs (the log viewer is the same everywhere it appears). */
object Ui {

    fun showLog(context: Context) {
        val text = Logs.tail(context)
        val view = TextView(context).apply {
            this.text = text.ifBlank { context.getString(R.string.log_empty) }
            typeface = Typeface.MONOSPACE
            textSize = 10f
            setTextColor(context.getColor(R.color.es_text_soft))
            setPadding(24, 24, 24, 24)
            movementMethod = ScrollingMovementMethod()
            setTextIsSelectable(true)
        }
        val scroll = ScrollView(context).apply { addView(view) }
        AlertDialog.Builder(context)
            .setTitle(R.string.log_viewer_title)
            .setView(scroll)
            .setPositiveButton(R.string.btn_close, null)
            .setNeutralButton(R.string.about_share_logs) { _, _ -> Logs.share(context) }
            .show()
    }

    fun confirm(context: Context, titleRes: Int, messageRes: Int, onConfirm: () -> Unit) {
        AlertDialog.Builder(context)
            .setTitle(titleRes)
            .setMessage(messageRes)
            .setPositiveButton(R.string.btn_ok) { _, _ -> onConfirm() }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }
}
