package it.dadaloop.evershelf.health

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.MenuItem
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import it.dadaloop.evershelf.health.databinding.ActivityMainBinding
import kotlinx.coroutines.launch

/** One screen, five tabs: Today, Diary, Charts, Control, Info. */
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding

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

        binding.bottomNav.setOnItemSelectedListener { item ->
            showTab(item.itemId)
            true
        }
        if (savedInstanceState == null) {
            showTab(R.id.nav_dashboard)
        }

        BatteryHelper.requestNotificationPermission(this)
        SyncEngine.schedulePeriodic(this)
        if (Prefs.keepAlive(this)) KeepAliveService.start(this)
        SyncEngine.requestImmediate(this)
        maybeCheckUpdates()
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
    }

    private fun showTab(itemId: Int) {
        val tag = when (itemId) {
            R.id.nav_diary -> "diary"
            R.id.nav_charts -> "charts"
            R.id.nav_control -> "control"
            R.id.nav_about -> "about"
            else -> "dashboard"
        }
        val label = when (tag) {
            "diary" -> R.string.nav_diary
            "charts" -> R.string.nav_charts
            "control" -> R.string.nav_control
            "about" -> R.string.nav_about
            else -> R.string.nav_dashboard
        }
        supportActionBar?.subtitle = getString(label)

        val fm = supportFragmentManager
        val tx = fm.beginTransaction()
        for (f in fm.fragments) {
            if (f.tag != tag && !f.isHidden) tx.hide(f)
        }
        val existing = fm.findFragmentByTag(tag)
        if (existing == null) {
            tx.add(R.id.nav_host_fragment, newFragment(tag), tag)
        } else {
            tx.show(existing)
        }
        tx.commitAllowingStateLoss()
    }

    private fun newFragment(tag: String): Fragment = when (tag) {
        "diary" -> DiaryFragment()
        "charts" -> ChartsFragment()
        "control" -> ControlFragment()
        "about" -> AboutFragment()
        else -> DashboardFragment()
    }

    override fun onCreateOptionsMenu(menu: android.view.Menu): Boolean {
        menuInflater.inflate(R.menu.main_menu, menu)
        return true
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        return when (item.itemId) {
            R.id.action_sync -> {
                doSync()
                true
            }
            R.id.action_setup -> {
                startActivity(Intent(this, WizardActivity::class.java))
                true
            }
            R.id.action_open_hc -> {
                openHealthConnect()
                true
            }
            R.id.action_update -> {
                binding.bottomNav.selectedItemId = R.id.nav_about
                true
            }
            else -> super.onOptionsItemSelected(item)
        }
    }

    private fun doSync() {
        Toast.makeText(this, R.string.dash_syncing, Toast.LENGTH_SHORT).show()
        lifecycleScope.launch {
            val outcome = SyncEngine.syncNow(this@MainActivity, "manual")
            val text = when {
                !outcome.ok && outcome.message == "not_configured" -> getString(R.string.err_not_configured)
                outcome.deferred -> getString(R.string.dash_sync_deferred)
                outcome.ok -> getString(R.string.dash_sync_ok)
                else -> getString(R.string.dash_sync_fail, outcome.message)
            }
            Toast.makeText(this@MainActivity, text, Toast.LENGTH_LONG).show()
        }
    }

    private fun maybeCheckUpdates() {
        lifecycleScope.launch {
            try {
                val info = Updater.check(this@MainActivity) ?: return@launch
                Toast.makeText(
                    this@MainActivity,
                    getString(R.string.about_update_available, info.version),
                    Toast.LENGTH_LONG
                ).show()
                binding.bottomNav.selectedItemId = R.id.nav_about
            } catch (e: Throwable) {
                Logs.warn(this@MainActivity, "update", "check failed: ${e.message}")
            }
        }
    }

    private fun openHealthConnect() {
        try {
            startActivity(Intent(Intent.ACTION_VIEW).setPackage("com.google.android.apps.healthdata"))
        } catch (_: ActivityNotFoundException) {
            try {
                startActivity(
                    Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=com.google.android.apps.healthdata"))
                )
            } catch (_: Exception) {
                Toast.makeText(this, R.string.dash_hc_missing, Toast.LENGTH_LONG).show()
            }
        }
    }
}
