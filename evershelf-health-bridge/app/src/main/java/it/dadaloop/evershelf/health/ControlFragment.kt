package it.dadaloop.evershelf.health

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.lifecycle.lifecycleScope
import it.dadaloop.evershelf.health.databinding.FragmentControlBinding
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** The control panel: pairing, connection, pace, permissions and the local data. */
class ControlFragment : Fragment() {

    private var _binding: FragmentControlBinding? = null
    private val binding get() = _binding!!

    private val permissionLauncher = registerForActivityResult(
        PermissionController.createRequestPermissionResultContract()
    ) { Toast.makeText(requireContext(), R.string.toast_perms_ok, Toast.LENGTH_SHORT).show(); refresh() }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, state: Bundle?): View {
        _binding = FragmentControlBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        val ctx = requireContext()

        binding.ctrlPairBtn.setOnClickListener {
            startActivity(Intent(ctx, WizardActivity::class.java))
        }
        binding.ctrlTestBtn.setOnClickListener {
            binding.ctrlTestBtn.isEnabled = false
            binding.ctrlReachState.setText(R.string.control_checking)
            lifecycleScope.launch {
                val ok = ServerReachability.check(ctx, force = true)
                if (_binding == null) return@launch
                binding.ctrlReachState.setText(if (ok) R.string.control_connected else R.string.control_disconnected)
                binding.ctrlTestBtn.isEnabled = true
            }
        }

        binding.swAutoSync.setOnCheckedChangeListener { _, checked ->
            Prefs.setAutoSync(ctx, checked)
            SyncEngine.schedulePeriodic(ctx)
        }
        binding.swWifiOnly.setOnCheckedChangeListener { _, checked -> Prefs.setWifiOnly(ctx, checked) }
        binding.swNotifications.setOnCheckedChangeListener { _, checked ->
            Prefs.setNotifications(ctx, checked)
            if (!checked) BridgeNotifier.cancel(ctx)
            else if (Prefs.keepAlive(ctx)) KeepAliveService.start(ctx)
        }
        binding.swKeepalive.setOnCheckedChangeListener { _, checked ->
            Prefs.setKeepAlive(ctx, checked)
            if (checked) KeepAliveService.start(ctx) else KeepAliveService.stop(ctx)
        }

        binding.intervalMinus.setOnClickListener { changeInterval(-15) }
        binding.intervalPlus.setOnClickListener { changeInterval(15) }

        binding.batteryRow.setOnClickListener {
            BatteryHelper.requestIgnoreBatteryOptimizations(requireActivity())
        }
        binding.permsBtn.setOnClickListener { requestPermissions() }
        binding.hcBtn.setOnClickListener {
            runCatching { startActivity(Intent(Intent.ACTION_VIEW).setPackage("com.google.android.apps.healthdata")) }
                .onFailure { Toast.makeText(ctx, R.string.dash_hc_missing, Toast.LENGTH_LONG).show() }
        }

        binding.sendQueueBtn.setOnClickListener {
            binding.sendQueueBtn.isEnabled = false
            lifecycleScope.launch {
                val outcome = SyncEngine.syncNow(ctx, "manual")
                binding.sendQueueBtn.isEnabled = true
                val text = when {
                    outcome.deferred -> getString(R.string.dash_sync_deferred)
                    outcome.ok -> getString(R.string.dash_sync_ok)
                    else -> getString(R.string.dash_sync_fail, outcome.message)
                }
                Toast.makeText(ctx, text, Toast.LENGTH_LONG).show()
                refresh()
            }
        }
        binding.backfillBtn.setOnClickListener {
            binding.backfillBtn.isEnabled = false
            lifecycleScope.launch {
                val days = Prefs.backfillDays(ctx)
                val stored = SyncEngine.backfill(ctx, days)
                binding.backfillBtn.isEnabled = true
                Toast.makeText(ctx, getString(R.string.diary_refresh_done) + " · $stored", Toast.LENGTH_LONG).show()
                refresh()
            }
        }
        binding.logsBtn.setOnClickListener { Ui.showLog(ctx) }
        binding.clearBtn.setOnClickListener {
            Ui.confirm(ctx, R.string.control_clear, R.string.control_clear_confirm) {
                lifecycleScope.launch(Dispatchers.IO) {
                    HealthDb(ctx).deleteAll()
                    withContext(Dispatchers.Main) {
                        Toast.makeText(ctx, R.string.control_clear_done, Toast.LENGTH_SHORT).show()
                        refresh()
                    }
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        if (_binding != null) refresh()
    }

    private fun changeInterval(delta: Int) {
        val ctx = requireContext()
        val next = (Prefs.syncIntervalMin(ctx) + delta).coerceIn(15, 240)
        Prefs.setSyncIntervalMin(ctx, next)
        binding.intervalValue.text = getString(R.string.control_interval_value, next)
        SyncEngine.schedulePeriodic(ctx)
    }

    private fun requestPermissions() {
        when (HealthConnectReader.sdkStatus(requireContext())) {
            HealthConnectClient.SDK_AVAILABLE -> runCatching {
                permissionLauncher.launch(HealthConnectReader.PERMISSIONS)
            }.onFailure {
                Toast.makeText(requireContext(), R.string.err_hc_unavailable, Toast.LENGTH_LONG).show()
            }
            else -> Toast.makeText(requireContext(), R.string.dash_hc_missing, Toast.LENGTH_LONG).show()
        }
    }

    private fun refresh() {
        val ctx = requireContext()
        val paired = Prefs.isConfigured(ctx)
        binding.ctrlPairState.setText(if (paired) R.string.control_paired else R.string.control_not_paired)
        binding.ctrlPairState.setBackgroundResource(if (paired) R.drawable.bg_chip_mint else R.drawable.bg_chip_amber)
        binding.ctrlUrl.text = Prefs.url(ctx).ifBlank { "—" }
        binding.ctrlToken.text = maskToken(Prefs.token(ctx))

        binding.ctrlReachState.text = when (ServerReachability.cached()) {
            true -> getString(R.string.control_connected)
            false -> getString(R.string.control_disconnected)
            null -> ""
        }

        binding.swAutoSync.isChecked = Prefs.autoSync(ctx)
        binding.swWifiOnly.isChecked = Prefs.wifiOnly(ctx)
        binding.swNotifications.isChecked = Prefs.notifications(ctx)
        binding.swKeepalive.isChecked = Prefs.keepAlive(ctx)
        binding.intervalValue.text = getString(R.string.control_interval_value, Prefs.syncIntervalMin(ctx))

        val pending = HealthDb(ctx).pendingCount()
        binding.ctrlPendingText.text = if (pending > 0) {
            getString(R.string.control_pending_count, pending)
        } else {
            getString(R.string.control_pending_none)
        }
        binding.backfillHint.text = getString(R.string.control_backfill_hint, Prefs.backfillDays(ctx))
        binding.batteryState.setText(
            if (BatteryHelper.isIgnoringBatteryOptimizations(ctx)) R.string.control_battery_ok
            else R.string.control_battery_needed
        )

        lifecycleScope.launch {
            val granted = runCatching { HealthConnectReader.grantedCount(ctx) }.getOrDefault(0)
            if (_binding == null) return@launch
            val total = HealthConnectReader.PERMISSIONS.size
            binding.permsState.text = if (granted >= total) {
                getString(R.string.control_perms_ok)
            } else {
                getString(R.string.control_perms_missing, total - granted)
            }
        }
    }

    private fun maskToken(token: String): String = when {
        token.isBlank() -> "—"
        token.length <= 14 -> token
        else -> token.take(14) + "…" + token.takeLast(4)
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
