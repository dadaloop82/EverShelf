package it.dadaloop.evershelf.health

import androidx.annotation.StringRes

/**
 * One metric the bridge can chart, with the JSON key it is stored under.
 *
 * The same descriptors feed the Charts chips, the diary line and the Today tiles, so a metric
 * cannot be formatted one way in one screen and another way in the next.
 */
data class Metric(
    val key: String,
    @StringRes val labelRes: Int,
    val unit: String,
    val decimals: Int,
    val colorRes: Int,
    val secondKey: String? = null,
    @StringRes val secondLabelRes: Int? = null,
    val scale: Double = 1.0,
)

object Metrics {
    val ALL: List<Metric> = listOf(
        Metric("steps", R.string.metric_steps, "", 0, R.color.chart_emerald),
        Metric("burned_kcal", R.string.metric_kcal, "kcal", 0, R.color.chart_amber),
        Metric("sleep_hours", R.string.metric_sleep, "h", 1, R.color.chart_violet),
        Metric("weight_kg", R.string.metric_weight, "kg", 1, R.color.chart_blue),
        Metric("hr_avg", R.string.metric_hr, "bpm", 0, R.color.chart_rose),
        Metric("resting_hr", R.string.metric_resting_hr, "bpm", 0, R.color.chart_rose),
        Metric("hrv_ms", R.string.metric_hrv, "ms", 0, R.color.chart_violet),
        Metric("spo2_pct", R.string.metric_spo2, "%", 0, R.color.chart_blue),
        Metric("respiratory_rate", R.string.metric_resp, "rpm", 1, R.color.chart_blue),
        Metric("hydration_ml", R.string.metric_hydration, "ml", 0, R.color.chart_blue),
        Metric("distance_km", R.string.metric_distance, "km", 2, R.color.chart_emerald),
        Metric("floors", R.string.metric_floors, "", 0, R.color.chart_amber),
        Metric("body_fat_pct", R.string.metric_bodyfat, "%", 1, R.color.chart_amber),
        Metric("bp_sys", R.string.metric_bp, "mmHg", 0, R.color.chart_rose,
            secondKey = "bp_dia", secondLabelRes = R.string.metric_bp),
        Metric("blood_glucose", R.string.metric_glucose, "mg/dL", 0, R.color.chart_amber),
        Metric("body_temperature_c", R.string.metric_temp, "°C", 1, R.color.chart_rose),
        Metric("basal_kcal", R.string.metric_bmr, "kcal", 0, R.color.chart_amber),
    )

    fun byKey(key: String): Metric? = ALL.firstOrNull { it.key == key }

    fun value(row: DayRow, metric: Metric): Double? {
        val j = row.obj()
        if (!j.has(metric.key) || j.isNull(metric.key)) return null
        val raw = j.optDouble(metric.key, Double.NaN)
        if (raw.isNaN()) return null
        return raw * metric.scale
    }

    fun secondValue(row: DayRow, metric: Metric): Double? {
        val key = metric.secondKey ?: return null
        val j = row.obj()
        if (!j.has(key) || j.isNull(key)) return null
        val raw = j.optDouble(key, Double.NaN)
        return if (raw.isNaN()) null else raw
    }
}
