package it.dadaloop.evershelf.health

import android.content.Context
import android.graphics.Canvas
import android.graphics.LinearGradient
import android.graphics.Paint
import android.graphics.Path
import android.graphics.Shader
import android.util.AttributeSet
import android.view.View
import androidx.core.content.ContextCompat
import java.text.DecimalFormat
import java.util.Locale
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * A line chart drawn with the Canvas: no chart library, no build-time risk, and the same look
 * in light and dark because every colour comes from the theme's resources.
 */
class MetricChartView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyle: Int = 0,
) : View(context, attrs, defStyle) {

    data class Series(val label: String, val color: Int, val points: List<Float?>, val dashed: Boolean = false)

    private var labels: List<String> = emptyList()
    private var series: List<Series> = emptyList()
    private var unit: String = ""
    private var decimals: Int = 0

    private val gridPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = ContextCompat.getColor(context, R.color.chart_grid)
        strokeWidth = dp(1f)
    }
    private val textPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = ContextCompat.getColor(context, R.color.es_muted)
        textSize = dp(10f)
    }
    private val linePaints = HashMap<Int, Paint>()
    private val fillPaint = Paint(Paint.ANTI_ALIAS_FLAG)
    private val path = Path()
    private val numberFormat = DecimalFormat("#,##0.###")

    fun setChart(labels: List<String>, series: List<Series>, unit: String, decimals: Int) {
        this.labels = labels
        this.series = series
        this.unit = unit
        this.decimals = decimals.coerceIn(0, 3)
        invalidate()
    }

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        val w = MeasureSpec.getSize(widthMeasureSpec)
        val h = MeasureSpec.getSize(heightMeasureSpec)
        setMeasuredDimension(w, if (h > 0) h else dp(220f).toInt())
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        if (series.isEmpty() || labels.isEmpty()) return

        val left = dp(44f)
        val right = width - dp(10f)
        val top = dp(12f)
        val bottom = height - dp(22f)
        if (right <= left || bottom <= top) return

        val values = series.flatMap { it.points }.filterNotNull()
        if (values.isEmpty()) return
        var minV = values.min()
        var maxV = values.max()
        if (maxV - minV < 1e-6) {
            minV -= 1f
            maxV += 1f
        }
        val pad = (maxV - minV) * 0.12f
        minV -= pad
        maxV += pad

        // ── Grid + y labels ───────────────────────────────────────────────
        val steps = 4
        for (i in 0..steps) {
            val y = bottom - (bottom - top) * i / steps
            canvas.drawLine(left, y, right, y, gridPaint)
            val v = minV + (maxV - minV) * i / steps
            val text = formatValue(v)
            canvas.drawText(text, dp(4f), y + dp(3.5f), textPaint)
        }

        // ── X labels: first, middle, last ─────────────────────────────────
        if (labels.size >= 2) {
            val idx = intArrayOf(0, labels.size / 2, labels.size - 1)
            for (k in idx) {
                val cx = left + (right - left) * k / (labels.size - 1)
                val anchor = when (k) {
                    0 -> Paint.Align.LEFT
                    labels.size - 1 -> Paint.Align.RIGHT
                    else -> Paint.Align.CENTER
                }
                textPaint.textAlign = anchor
                canvas.drawText(labels[k], cx, height - dp(6f), textPaint)
            }
            textPaint.textAlign = Paint.Align.LEFT
        }

        // ── Series ────────────────────────────────────────────────────────
        for (s in series) {
            val paint = linePaints.getOrPut(s.color) {
                Paint(Paint.ANTI_ALIAS_FLAG).apply {
                    style = Paint.Style.STROKE
                    strokeWidth = dp(2.4f)
                    strokeCap = Paint.Cap.ROUND
                    strokeJoin = Paint.Join.ROUND
                    color = s.color
                }
            }
            paint.color = s.color
            paint.pathEffect = if (s.dashed) {
                android.graphics.DashPathEffect(floatArrayOf(dp(6f), dp(4f)), 0f)
            } else {
                null
            }
            path.reset()
            var started = false
            var lastX = 0f
            var lastY = 0f
            for (i in s.points.indices) {
                val v = s.points[i]
                if (v == null) {
                    started = false
                    continue
                }
                val x = left + (right - left) * i / max(1, labels.size - 1).toFloat()
                val y = bottom - (bottom - top) * ((v - minV) / (maxV - minV))
                if (!started) {
                    path.moveTo(x, y)
                    started = true
                } else {
                    path.lineTo(x, y)
                }
                lastX = x
                lastY = y
            }
            canvas.drawPath(path, paint)
            // A single point has no line: draw it as a dot.
            if (s.points.count { it != null } == 1) {
                canvas.drawCircle(lastX, lastY, dp(3f), Paint(Paint.ANTI_ALIAS_FLAG).apply { color = s.color })
            }
            // A soft fill under the FIRST series only: two washes would hide each other.
            if (series.indexOf(s) == 0) {
                fillPath(s, left, right, top, bottom, minV, maxV)
                fillPaint.shader = LinearGradient(
                    0f, top, 0f, bottom,
                    (s.color and 0x00FFFFFF) or 0x33000000,
                    (s.color and 0x00FFFFFF),
                    Shader.TileMode.CLAMP
                )
                canvas.drawPath(path, fillPaint)
            }
        }
    }

    /** Builds the area under the line (reuses [path] is not possible: the stroke is needed). */
    private fun fillPath(s: Series, left: Float, right: Float, top: Float, bottom: Float, minV: Float, maxV: Float) {
        path.reset()
        var started = false
        for (i in s.points.indices) {
            val v = s.points[i] ?: continue
            val x = left + (right - left) * i / max(1, labels.size - 1).toFloat()
            val y = bottom - (bottom - top) * ((v - minV) / (maxV - minV))
            if (!started) {
                path.moveTo(x, bottom)
                path.lineTo(x, y)
                started = true
            } else {
                path.lineTo(x, y)
            }
        }
        if (started) {
            val lastIndex = s.points.indexOfLast { it != null }
            val lastX = left + (right - left) * lastIndex / max(1, labels.size - 1).toFloat()
            path.lineTo(lastX, bottom)
            path.close()
        }
    }

    fun formatValue(v: Float): String {
        val text = when (decimals) {
            0 -> v.roundToInt().toString()
            1 -> String.format(Locale.US, "%.1f", v)
            2 -> String.format(Locale.US, "%.2f", v)
            else -> numberFormat.format(v)
        }
        return text
    }

    /** Human summary used by the screen under the chart. */
    fun summary(values: List<Float>): String {
        if (values.isEmpty()) return ""
        val avg = values.average()
        return "${formatValue(avg.toFloat())} ${unit}".trim()
    }

    private fun dp(value: Float): Float = value * resources.displayMetrics.density
}
