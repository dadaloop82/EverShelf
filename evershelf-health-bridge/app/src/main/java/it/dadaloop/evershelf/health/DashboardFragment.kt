package it.dadaloop.evershelf.health

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import android.widget.Toast
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.lifecycle.lifecycleScope
import it.dadaloop.evershelf.health.databinding.FragmentDashboardBinding
import it.dadaloop.evershelf.health.databinding.ItemMetricRowBinding
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Date

/** Today, as the phone measured it, and what the bridge is doing with it. */
class DashboardFragment : Fragment() {

    private var _binding: FragmentDashboardBinding? = null
    private val binding get() = _binding!!

    private val permissionLauncher = registerForActivityResult(
        PermissionController.createRequestPermissionResultContract()
    ) { granted ->
        Toast.makeText(
            requireContext(),
            if (granted.isNotEmpty()) R.string.toast_perms_ok else R.string.toast_perms_incomplete,
            Toast.LENGTH_SHORT
        ).show()
        refresh()
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, state: Bundle?): View {
        _binding = FragmentDashboardBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        val ctx = requireContext()
        binding.heroDate.text = LocalDate.now().format(DateTimeFormatter.ofPattern("EEEE d MMMM", LocaleHelper.locale(ctx))).uppercase(LocaleHelper.locale(ctx))
        binding.swDashboard.setOnRefreshListener {
            lifecycleScope.launch {
                SyncEngine.syncNow(requireContext(), "pull")
                refresh()
                binding.swDashboard.isRefreshing = false
            }
        }
        binding.btnSync.setOnClickListener {
            binding.btnSync.isEnabled = false
            binding.btnSync.setText(R.string.dash_syncing)
            lifecycleScope.launch {
                val outcome = SyncEngine.syncNow(requireContext(), "manual")
                binding.btnSync.isEnabled = true
                binding.btnSync.setText(R.string.main_sync_now)
                val text = when {
                    outcome.deferred -> getString(R.string.dash_sync_deferred)
                    outcome.ok -> getString(R.string.dash_sync_ok)
                    else -> getString(R.string.dash_sync_fail, outcome.message)
                }
                Toast.makeText(requireContext(), text, Toast.LENGTH_LONG).show()
                refresh()
            }
        }
        binding.btnGrant.setOnClickListener { requestPermissions() }
    }

    override fun onResume() {
        super.onResume()
        if (_binding != null) refresh()
    }

    private fun requestPermissions() {
        when (HealthConnectReader.sdkStatus(requireContext())) {
            HealthConnectClient.SDK_AVAILABLE -> {
                runCatching { permissionLauncher.launch(HealthConnectReader.PERMISSIONS) }
                    .onFailure { Toast.makeText(requireContext(), R.string.err_hc_unavailable, Toast.LENGTH_LONG).show() }
            }
            else -> Toast.makeText(requireContext(), R.string.dash_hc_missing, Toast.LENGTH_LONG).show()
        }
    }

    private fun refresh() {
        val ctx = requireContext()
        val db = HealthDb(ctx)
        val today = LocalDate.now().toString()
        val row = db.getDay(today)
        val json = row?.obj() ?: runCatching { JSONObject(Prefs.lastPayloadJson(ctx)) }.getOrDefault(JSONObject())
        render(json)

        val pending = db.pendingCount()
        binding.pendingText.text = if (pending > 0) {
            getString(R.string.dash_pending, pending)
        } else {
            getString(R.string.dash_pending_none)
        }

        val last = Prefs.lastSync(ctx)
        val lastText = if (last == 0L) getString(R.string.dash_last_sync_none)
        else getString(
            R.string.dash_last_sync,
            SimpleDateFormat("dd/MM HH:mm", LocaleHelper.locale(ctx)).format(Date(last))
        )
        binding.statusDetail.text = lastText

        val paired = Prefs.isConfigured(ctx)
        val reach = ServerReachability.cached()
        binding.statusChip.text = when {
            !paired -> getString(R.string.dash_not_linked)
            reach == true -> getString(R.string.dash_reachable)
            reach == false -> getString(R.string.dash_unreachable)
            else -> getString(R.string.dash_checking)
        }
        binding.statusChip.setBackgroundResource(
            if (paired && reach != false) R.drawable.bg_chip_mint else R.drawable.bg_chip_amber
        )

        val sdkOk = HealthConnectReader.sdkStatus(ctx) == HealthConnectClient.SDK_AVAILABLE
        // Health Connect answers asynchronously: never block the UI thread on it.
        lifecycleScope.launch {
            val granted = runCatching { HealthConnectReader.grantedCount(ctx) }.getOrDefault(0)
            if (_binding == null) return@launch
            val warning = when {
                !sdkOk -> getString(R.string.dash_hc_missing)
                granted == 0 -> getString(R.string.dash_perms_needed)
                else -> null
            }
            binding.dashWarning.text = warning ?: ""
            binding.dashWarning.visibility = if (warning == null) View.GONE else View.VISIBLE
            binding.btnGrant.visibility =
                if (!sdkOk || granted < HealthConnectReader.PERMISSIONS.size) View.VISIBLE else View.GONE
        }
    }

    private fun render(json: JSONObject) {
        val ctx = requireContext()
        val steps = json.optLong("steps", 0L)
        val kcal = json.optInt("burned_kcal", json.optInt("active_kcal", 0))
        val stepsGoal = Prefs.stepsGoal(ctx)
        val kcalGoal = Prefs.kcalGoal(ctx)

        binding.ringSteps.setData(
            if (stepsGoal > 0) steps.toFloat() / stepsGoal else 0f,
            compact(steps),
            getString(R.string.dash_steps),
            ContextCompat.getColor(ctx, R.color.chart_emerald)
        )
        binding.ringKcal.setData(
            if (kcalGoal > 0) kcal.toFloat() / kcalGoal else 0f,
            compact(kcal.toLong()),
            getString(R.string.dash_kcal),
            ContextCompat.getColor(ctx, R.color.chart_amber)
        )

        val sleep = if (json.has("sleep_hours")) json.optDouble("sleep_hours") else null
        binding.tileSleepValue.text = sleep?.let { String.format(LocaleHelper.locale(ctx), "%.1f h", it) } ?: "—"
        val stages = buildList {
            json.optDouble("sleep_deep_h", 0.0).takeIf { it > 0 }?.let { add("deep " + String.format(LocaleHelper.locale(ctx), "%.1f", it)) }
            json.optDouble("sleep_rem_h", 0.0).takeIf { it > 0 }?.let { add("rem " + String.format(LocaleHelper.locale(ctx), "%.1f", it)) }
        }.joinToString(" · ")
        binding.tileSleepSub.text = stages

        val exercise = if (json.has("exercise_min")) json.optInt("exercise_min") else null
        binding.tileExerciseValue.text = exercise?.let { "$it min" } ?: "—"
        val types = json.optJSONArray("exercise_types")
        binding.tileExerciseSub.text = if (types == null || types.length() == 0) "" else
            (0 until types.length()).joinToString(", ") { types.optString(it) }

        binding.metricsContainer.removeAllViews()
        addMetric(R.string.dash_water, ml(json.optInt("hydration_ml", -1)))
        addMetric(R.string.dash_distance, if (json.has("distance_km")) String.format(LocaleHelper.locale(ctx), "%.2f km", json.optDouble("distance_km")) else "—")
        addMetric(R.string.dash_floors, json.optInt("floors", -1).takeIf { it >= 0 }?.toString() ?: "—")
        addMetric(R.string.dash_hr, json.optInt("hr_avg", -1).takeIf { it >= 0 }?.let { "$it bpm" } ?: "—")
        addMetric(R.string.metric_resting_hr, json.optInt("resting_hr", -1).takeIf { it >= 0 }?.let { "$it bpm" } ?: "—")
        addMetric(R.string.dash_spo2, json.optInt("spo2_pct", -1).takeIf { it >= 0 }?.let { "$it %" } ?: "—")
        addMetric(R.string.metric_hrv, json.optInt("hrv_ms", -1).takeIf { it >= 0 }?.let { "$it ms" } ?: "—")
        addMetric(R.string.metric_resp, json.optDouble("respiratory_rate", -1.0).takeIf { it > 0 }?.let { String.format(LocaleHelper.locale(ctx), "%.1f rpm", it) } ?: "—")
        addMetric(R.string.dash_weight, json.optDouble("weight_kg", -1.0).takeIf { it > 0 }?.let { String.format(LocaleHelper.locale(ctx), "%.1f kg", it) } ?: "—")
        addMetric(R.string.metric_bodyfat, json.optDouble("body_fat_pct", -1.0).takeIf { it > 0 }?.let { String.format(LocaleHelper.locale(ctx), "%.1f %%", it) } ?: "—")
        addMetric(R.string.dash_bp, if (json.has("bp_sys")) "${json.optInt("bp_sys")}/${json.optInt("bp_dia")} mmHg" else "—")
        addMetric(R.string.metric_glucose, json.optInt("blood_glucose", -1).takeIf { it >= 0 }?.let { "$it mg/dL" } ?: "—")
        addMetric(R.string.metric_temp, json.optDouble("body_temperature_c", -1.0).takeIf { it > 0 }?.let { String.format(LocaleHelper.locale(ctx), "%.1f °C", it) } ?: "—")
        addMetric(R.string.dash_bmr, json.optInt("basal_kcal", -1).takeIf { it >= 0 }?.let { "$it kcal" } ?: "—")
    }

    private fun addMetric(labelRes: Int, value: String) {
        val row = ItemMetricRowBinding.inflate(layoutInflater, binding.metricsContainer, false)
        row.metricLabel.setText(labelRes)
        row.metricValue.text = value
        binding.metricsContainer.addView(row.root, LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
    }

    private fun compact(value: Long): String = when {
        value >= 100_000 -> String.format(LocaleHelper.locale(requireContext()), "%.0fk", value / 1000.0)
        value >= 10_000 -> String.format(LocaleHelper.locale(requireContext()), "%.1fk", value / 1000.0)
        else -> value.toString()
    }

    private fun ml(value: Int): String = if (value <= 0) "—" else "$value ml"

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
