package it.dadaloop.evershelf.health

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.lifecycle.lifecycleScope
import it.dadaloop.evershelf.health.databinding.ActivityMainBinding
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding

    private val permissionLauncher = registerForActivityResult(
        PermissionController.createRequestPermissionResultContract()
    ) { granted ->
        Toast.makeText(
            this,
            if (granted.containsAll(HealthConnectReader.PERMISSIONS)) R.string.toast_perms_ok
            else R.string.toast_perms_incomplete,
            Toast.LENGTH_SHORT
        ).show()
        refreshUi()
    }

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(LocaleHelper.wrap(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!Prefs.isConfigured(this)) {
            startActivity(Intent(this, WizardActivity::class.java))
            finish()
            return
        }
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)
        setSupportActionBar(binding.toolbar)
        SyncHelper.schedulePeriodic(this)
        BatteryHelper.ensureBackgroundSurvival(this)

        binding.heroTitle.setText(R.string.main_hero)
        binding.heroSubtitle.setText(R.string.main_subtitle)
        binding.btnSync.setText(R.string.main_sync_now)
        binding.btnPermissions.setText(R.string.main_perms)
        binding.btnSetup.setText(R.string.main_reconfigure)
        binding.btnBattery.setText(R.string.main_battery)
        binding.btnBattery.setOnClickListener {
            BatteryHelper.requestIgnoreBatteryOptimizations(this)
        }

        binding.btnSync.setOnClickListener { doSync() }
        binding.btnPermissions.setOnClickListener { requestHcPermissions() }
        binding.btnSetup.setOnClickListener {
            startActivity(Intent(this, WizardActivity::class.java))
        }
    }

    override fun onResume() {
        super.onResume()
        if (::binding.isInitialized) refreshUi()
    }

    override fun onCreateOptionsMenu(menu: android.view.Menu?): Boolean {
        menuInflater.inflate(R.menu.main_menu, menu)
        return true
    }

    override fun onOptionsItemSelected(item: android.view.MenuItem): Boolean {
        return when (item.itemId) {
            R.id.action_setup -> {
                startActivity(Intent(this, WizardActivity::class.java)); true
            }
            R.id.action_open_hc -> {
                openHealthConnect(); true
            }
            else -> super.onOptionsItemSelected(item)
        }
    }

    private fun refreshUi() {
        val url = Prefs.url(this)
        binding.serverLine.text = if (url.isBlank()) getString(R.string.main_not_linked) else url
        val last = Prefs.lastSync(this)
        binding.lastSyncLine.text = if (last == 0L) getString(R.string.main_last_sync_none)
        else getString(
            R.string.main_last_sync,
            SimpleDateFormat("dd/MM HH:mm", Locale.getDefault()).format(Date(last))
        )

        val raw = Prefs.lastPayloadJson(this)
        if (raw.isNotBlank()) {
            try {
                val j = JSONObject(raw)
                binding.metricKcal.text = "🔥 ${j.opt("burned_kcal") ?: "—"} kcal"
                binding.metricSteps.text = "👟 ${j.opt("steps") ?: "—"}"
                binding.metricExercise.text = "⏱ ${j.opt("exercise_min") ?: "—"} min"
                binding.metricSleep.text = "😴 ${j.opt("sleep_hours") ?: "—"} h"
                binding.statusLine.text = j.optString("source", "health_connect")
            } catch (_: Exception) {
                binding.statusLine.setText(R.string.main_waiting)
            }
        } else {
            binding.statusLine.setText(R.string.main_waiting)
        }

        if (HealthConnectReader.sdkStatus(this) != HealthConnectClient.SDK_AVAILABLE) {
            binding.statusLine.setText(R.string.main_hc_missing)
        }
        binding.btnBattery.alpha = if (BatteryHelper.isIgnoringBatteryOptimizations(this)) 0.45f else 1f
    }

    private fun requestHcPermissions() {
        when (HealthConnectReader.sdkStatus(this)) {
            HealthConnectClient.SDK_AVAILABLE ->
                permissionLauncher.launch(HealthConnectReader.PERMISSIONS)
            HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED -> openHealthConnect()
            else -> Toast.makeText(this, R.string.main_hc_missing, Toast.LENGTH_LONG).show()
        }
    }

    private fun openHealthConnect() {
        try {
            startActivity(Intent(Intent.ACTION_VIEW).setPackage("com.google.android.apps.healthdata"))
        } catch (_: Exception) {
            try {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=com.google.android.apps.healthdata")))
            } catch (_: Exception) {
                Toast.makeText(this, R.string.main_hc_missing, Toast.LENGTH_SHORT).show()
            }
        }
    }

    private fun doSync() {
        binding.btnSync.isEnabled = false
        binding.btnSync.setText(R.string.main_syncing)
        KeepAliveService.start(this)
        lifecycleScope.launch {
            val res = SyncHelper.syncNow(this@MainActivity)
            binding.btnSync.isEnabled = true
            binding.btnSync.setText(R.string.main_sync_now)
            Toast.makeText(
                this@MainActivity,
                if (res.isSuccess) getString(R.string.main_synced)
                else getString(R.string.main_error, res.exceptionOrNull()?.message ?: "sync"),
                Toast.LENGTH_LONG
            ).show()
            refreshUi()
        }
    }
}
