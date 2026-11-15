package it.dadaloop.evershelf.health

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.google.android.material.chip.Chip
import it.dadaloop.evershelf.health.databinding.FragmentChartsBinding
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

/** The history: one metric at a time, 7 / 30 / 90 days, read from the phone's own store. */
class ChartsFragment : Fragment() {

    private var _binding: FragmentChartsBinding? = null
    private val binding get() = _binding!!
    private var selectedMetric: Metric = Metrics.ALL.first()
    private var selectedRange: Int = 30
    private var renderJob: kotlinx.coroutines.Job? = null
    private var lastBackfillAt = 0L

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, state: Bundle?): View {
        _binding = FragmentChartsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        buildMetricChips()
        binding.range7.setOnCheckedChangeListener { _, checked -> if (checked) { selectedRange = 7; render() } }
        binding.range30.setOnCheckedChangeListener { _, checked -> if (checked) { selectedRange = 30; render() } }
        binding.range90.setOnCheckedChangeListener { _, checked -> if (checked) { selectedRange = 90; render() } }
        render()
    }

    private fun buildMetricChips() {
        val ctx = requireContext()
        binding.chartMetrics.removeAllViews()
        Metrics.ALL.forEachIndexed { index, metric ->
            val chip = Chip(ctx).apply {
                text = getString(metric.labelRes)
                isCheckable = true
                isChecked = index == 0
                isClickable = true
                setOnCheckedChangeListener { _, checked ->
                    if (checked) {
                        selectedMetric = metric
                        render()
                    }
                }
            }
            binding.chartMetrics.addView(chip)
        }
    }

    private fun render() {
        renderJob?.cancel()
        renderJob = lifecycleScope.launch {
            val ctx = requireContext()
            val metric = selectedMetric
            val days = selectedRange
            val db = HealthDb(ctx)
            val start = LocalDate.now().minusDays((days - 1).toLong())
            var rows = db.daysSince(start.toString())

            // The store is filled from Health Connect the first time a range is asked for.
            // Two guards: no permission means there is nothing to import, and a range is
            // never re-imported more than once every 10 minutes (a day without data would
            // otherwise make every chip tap read the whole phone again).
            val canRead = runCatching { HealthConnectReader.grantedCount(ctx) }.getOrDefault(0) > 0
            val backfillDue = System.currentTimeMillis() - lastBackfillAt > 10 * 60 * 1000L
            if (canRead && backfillDue && rows.size < days - 1) {
                lastBackfillAt = System.currentTimeMillis()
                binding.chartsProgress.visibility = View.VISIBLE
                binding.chartsProgressText.visibility = View.VISIBLE
                binding.chartsProgressText.text = getString(R.string.charts_loading)
                SyncEngine.backfill(ctx, days) { done ->
                    activity?.runOnUiThread {
                        if (_binding != null) {
                            binding.chartsProgressText.text = getString(R.string.charts_loading_progress, done, days)
                        }
                    }
                }
                rows = db.daysSince(start.toString())
                binding.chartsProgress.visibility = View.GONE
                binding.chartsProgressText.visibility = View.GONE
            }
            paint(metric, rows, start, days)
        }
    }

    private fun paint(metric: Metric, rows: List<DayRow>, start: LocalDate, days: Int) {
        val ctx = requireContext()
        val loc = LocaleHelper.locale(ctx)
        val byDate = rows.associateBy { it.date }
        val pattern = if (days <= 30) "d/M" else "d MMM"
        val formatter = DateTimeFormatter.ofPattern(pattern, loc)

        val labels = ArrayList<String>(days)
        val values = ArrayList<Float?>(days)
        val second = ArrayList<Float?>(days)
        val all = ArrayList<Float>()
        for (i in 0 until days) {
            val date = start.plusDays(i.toLong())
            labels.add(date.format(formatter))
            val row = byDate[date.toString()]
            val v = row?.let { Metrics.value(it, metric) }
            values.add(v?.toFloat())
            if (v != null) all.add(v.toFloat())
            second.add(row?.let { Metrics.secondValue(it, metric) }?.toFloat())
        }

        val color = ContextCompat.getColor(ctx, metric.colorRes)
        val series = ArrayList<MetricChartView.Series>()
        series.add(MetricChartView.Series(getString(metric.labelRes), color, values))
        if (metric.secondKey != null) {
            series.add(
                MetricChartView.Series(
                    metric.secondLabelRes?.let { getString(it) } ?: "",
                    ContextCompat.getColor(ctx, R.color.chart_blue),
                    second,
                    dashed = true
                )
            )
        }
        binding.chartView.setChart(labels, series, metric.unit, metric.decimals)

        val hasData = all.isNotEmpty()
        binding.chartEmpty.visibility = if (hasData) View.GONE else View.VISIBLE
        binding.chartView.visibility = if (hasData) View.VISIBLE else View.INVISIBLE
        binding.chartSummary.text = if (!hasData) "" else buildString {
            append(getString(R.string.charts_avg)).append(" ").append(fmt(all.average().toFloat(), metric))
            append("  ·  ").append(getString(R.string.charts_min)).append(" ").append(fmt(all.min(), metric))
            append("  ·  ").append(getString(R.string.charts_max)).append(" ").append(fmt(all.max(), metric))
        }
    }

    private fun fmt(value: Float, metric: Metric): String {
        val loc = LocaleHelper.locale(requireContext())
        val text = when (metric.decimals) {
            0 -> value.toLong().toString()
            1 -> String.format(loc, "%.1f", value)
            2 -> String.format(loc, "%.2f", value)
            else -> value.toString()
        }
        return "$text ${metric.unit}".trim()
    }

    override fun onDestroyView() {
        super.onDestroyView()
        renderJob?.cancel()
        _binding = null
    }
}
