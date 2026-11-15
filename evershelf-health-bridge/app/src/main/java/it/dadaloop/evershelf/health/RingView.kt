package it.dadaloop.evershelf.health

import android.content.Context
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.RectF
import android.util.AttributeSet
import android.view.View
import androidx.core.content.ContextCompat
import kotlin.math.min

/** A goal ring for the Today screen: value in the middle, label under it. */
class RingView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyle: Int = 0,
) : View(context, attrs, defStyle) {

    private var fraction = 0f
    private var value = "—"
    private var label = ""
    private var ringColor = ContextCompat.getColor(context, R.color.es_green)

    private val trackPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        style = Paint.Style.STROKE
        strokeWidth = dp(9f)
        color = ContextCompat.getColor(context, R.color.es_border)
    }
    private val arcPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        style = Paint.Style.STROKE
        strokeWidth = dp(9f)
        strokeCap = Paint.Cap.ROUND
    }
    private val valuePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        textAlign = Paint.Align.CENTER
        typeface = android.graphics.Typeface.create("sans-serif", android.graphics.Typeface.BOLD)
        color = ContextCompat.getColor(context, R.color.es_text)
    }
    private val labelPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        textAlign = Paint.Align.CENTER
        color = ContextCompat.getColor(context, R.color.es_muted)
    }
    private val rect = RectF()

    fun setData(fraction: Float, value: String, label: String, color: Int) {
        this.fraction = fraction.coerceIn(0f, 1f)
        this.value = value
        this.label = label
        this.ringColor = color
        invalidate()
    }

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        val w = MeasureSpec.getSize(widthMeasureSpec)
        val h = MeasureSpec.getSize(heightMeasureSpec)
        val size = if (h > 0) min(w, h) else dp(140f).toInt()
        setMeasuredDimension(w, size)
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        val cx = width / 2f
        val cy = height / 2f
        val radius = min(width, height) / 2f - trackPaint.strokeWidth
        rect.set(cx - radius, cy - radius, cx + radius, cy + radius)

        trackPaint.strokeWidth = dp(9f)
        arcPaint.strokeWidth = dp(9f)
        arcPaint.color = ringColor
        canvas.drawCircle(cx, cy, radius, trackPaint)
        if (fraction > 0f) {
            canvas.drawArc(rect, -90f, 360f * fraction, false, arcPaint)
        }

        valuePaint.textSize = radius * 0.56f
        labelPaint.textSize = radius * 0.24f
        canvas.drawText(value, cx, cy + valuePaint.textSize * 0.18f, valuePaint)
        if (label.isNotEmpty()) {
            canvas.drawText(label, cx, cy + radius * 0.62f, labelPaint)
        }
    }

    private fun dp(value: Float): Float = value * resources.displayMetrics.density
}
