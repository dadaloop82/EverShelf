package it.dadaloop.evershelf.health

import android.content.Context
import android.content.Intent
import android.graphics.Typeface
import android.net.Uri
import android.os.Bundle
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.annotation.StringRes
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.materialswitch.MaterialSwitch
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.launch
import org.json.JSONObject

/**
 * First run, the way EverShelf does it: language first, then every parameter, so nobody has
 * to hunt through the Control panel to make the bridge work. Nothing is written to the server:
 * the pairing is accepted only when `health_bridge_hello` really answers, and every option is
 * saved on the phone the moment it is chosen (the Control panel shows the same values later).
 *
 * Steps: 0 language · 1 welcome · 2 Health Connect · 3 pairing · 4 sync options ·
 *        5 goals · 6 privacy & diagnostics · 7 ready
 */
class WizardActivity : AppCompatActivity() {

    private var step = STEP_LANGUAGE
    private lateinit var container: FrameLayout
    private lateinit var dots: LinearLayout
    private lateinit var btnBack: MaterialButton
    private lateinit var btnNext: MaterialButton

    private var urlEdit: EditText? = null
    private var tokenEdit: EditText? = null
    private var pairStatus: TextView? = null
    private var finishStatus: TextView? = null

    private val permissionLauncher = registerForActivityResult(
        PermissionController.createRequestPermissionResultContract()
    ) { granted ->
        val ok = granted.containsAll(HealthConnectReader.PERMISSIONS)
        Toast.makeText(
            this,
            if (ok) R.string.wiz_perms_granted else R.string.wiz_perms_partial,
            Toast.LENGTH_SHORT
        ).show()
        showStep(STEP_PAIRING)
    }

    private val qrLauncher = registerForActivityResult(ScanContract()) { result ->
        val text = result.contents ?: return@registerForActivityResult
        applyPairingPayload(text)
    }

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(LocaleHelper.wrap(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_wizard)
        container = findViewById(R.id.stepContainer)
        dots = findViewById(R.id.progressDots)
        btnBack = findViewById(R.id.btnBack)
        btnNext = findViewById(R.id.btnNext)
        findViewById<TextView>(R.id.btnExit).setOnClickListener { confirmExit() }
        btnBack.setOnClickListener { go(-1) }
        btnNext.setOnClickListener { go(1) }

        // The pairing view must exist before a deep link can fill it, so the initial step is
        // painted first and the payload applied after it.
        val restored = savedInstanceState?.getInt(KEY_STEP, -1) ?: -1
        showStep(if (restored in STEP_LANGUAGE..STEP_READY) restored else defaultStartStep())
        intent?.data?.let { handleDeepLink(it) }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putInt(KEY_STEP, step)
    }

    /** A fresh install starts at the language; Settings → setup starts at the pairing. */
    private fun defaultStartStep(): Int =
        if (intent?.getBooleanExtra(EXTRA_RECONFIGURE, false) == true) STEP_PAIRING else STEP_LANGUAGE

    private fun confirmExit() {
        AlertDialog.Builder(this)
            .setTitle(R.string.wizard_exit_title)
            .setMessage(R.string.wizard_exit_message)
            .setPositiveButton(R.string.wizard_exit_confirm) { _, _ -> finishAffinity() }
            .setNegativeButton(R.string.wizard_exit_cancel, null)
            .show()
    }

    private fun go(delta: Int) {
        if (delta > 0) {
            when (step) {
                STEP_PAIRING -> {
                    btnNext.isEnabled = false
                    lifecycleScope.launch {
                        val ok = savePairing()
                        btnNext.isEnabled = true
                        if (ok) showStep(STEP_OPTIONS)
                    }
                    return
                }
                STEP_READY -> {
                    finishSetup()
                    return
                }
            }
        }
        showStep((step + delta).coerceIn(STEP_LANGUAGE, STEP_READY))
    }

    private fun finishSetup() {
        if (!Prefs.isConfigured(this)) {
            showStep(STEP_PAIRING)
            return
        }
        BatteryHelper.requestNotificationPermission(this)
        SyncEngine.schedulePeriodic(this)
        if (Prefs.keepAlive(this)) KeepAliveService.start(this)
        SyncEngine.requestImmediate(this)
        Logs.info(
            this, "wizard",
            "setup finished — url=${Prefs.url(this)} interval=${Prefs.syncIntervalMin(this)} " +
                "wifiOnly=${Prefs.wifiOnly(this)} keepAlive=${Prefs.keepAlive(this)} " +
                "report=${Prefs.autoReport(this)} goals=${Prefs.stepsGoal(this)}/${Prefs.kcalGoal(this)}"
        )
        val home = Intent(this, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        startActivity(home)
        finish()
    }

    private fun showStep(next: Int) {
        step = next
        container.removeAllViews()
        urlEdit = null
        tokenEdit = null
        pairStatus = null
        finishStatus = null
        container.addView(
            ScrollView(this).apply {
                isFillViewport = true
                addView(buildStep(next), ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
            }
        )
        paintDots(next)
        btnBack.visibility = if (next == STEP_LANGUAGE) View.INVISIBLE else View.VISIBLE
        btnNext.setText(if (next == STEP_READY) R.string.btn_finish else R.string.btn_next)
    }

    private fun paintDots(active: Int) {
        dots.removeAllViews()
        for (i in 0 until TOTAL_STEPS) {
            val dot = View(this)
            val size = (9 * resources.displayMetrics.density).toInt()
            val params = LinearLayout.LayoutParams(size, size)
            params.marginEnd = (6 * resources.displayMetrics.density).toInt()
            dot.layoutParams = params
            dot.setBackgroundResource(if (i == active) R.drawable.bg_chip_mint else R.drawable.bg_chip_neutral)
            dots.addView(dot)
        }
    }

    // ── Steps ─────────────────────────────────────────────────────────────────

    private fun buildStep(index: Int): View {
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.TOP
            setPadding(0, dp(4), 0, dp(12))
        }
        box.addView(hintText(getString(R.string.wiz_step_count, index + 1, TOTAL_STEPS)))
        when (index) {
            STEP_LANGUAGE -> buildLanguage(box)
            STEP_WELCOME -> {
                box.addView(title(getString(R.string.wiz_welcome_title)))
                box.addView(body(getString(R.string.wiz_welcome_body)))
            }
            STEP_PERMISSIONS -> {
                box.addView(title(getString(R.string.wiz_perms_title)))
                box.addView(body(getString(R.string.wiz_perms_body)))
                box.addView(primaryButton(getString(R.string.wiz_perms_btn)) { requestHealthPermissions() })
                box.addView(secondaryButton(getString(R.string.wiz_perms_open_store)) { openHealthConnect() })
            }
            STEP_PAIRING -> buildPairing(box)
            STEP_OPTIONS -> buildOptions(box)
            STEP_GOALS -> buildGoals(box)
            STEP_DIAGNOSTICS -> buildDiagnostics(box)
            else -> buildReady(box)
        }
        return box
    }

    private fun buildLanguage(box: LinearLayout) {
        box.addView(title(getString(R.string.wiz_lang_title)))
        box.addView(body(getString(R.string.wiz_lang_hint)))
        val current = LocaleHelper.language(this)
        val languages = listOf(
            "it" to "Italiano", "en" to "English", "de" to "Deutsch",
            "fr" to "Français", "es" to "Español", "zh" to "中文"
        )
        for ((code, label) in languages) {
            val isCurrent = code == current
            val button = if (isCurrent) primaryButton("✓ $label") {
                LocaleHelper.setLanguage(this, code)
                recreate()
            } else secondaryButton(label) {
                LocaleHelper.setLanguage(this, code)
                recreate()
            }
            box.addView(button)
        }
    }

    private fun buildPairing(box: LinearLayout) {
        box.addView(title(getString(R.string.wiz_pair_title)))
        box.addView(body(getString(R.string.wiz_pair_body)))
        box.addView(primaryButton(getString(R.string.wiz_pair_scan)) { scanQr() })
        box.addView(secondaryButton(getString(R.string.wiz_pair_discover)) { discover() })

        urlEdit = EditText(this).apply {
            hint = getString(R.string.wiz_pair_url_hint)
            inputType = InputType.TYPE_TEXT_VARIATION_URI
            setText(Prefs.url(this@WizardActivity))
        }
        tokenEdit = EditText(this).apply {
            hint = getString(R.string.wiz_pair_token_hint)
            inputType = InputType.TYPE_CLASS_TEXT
            setText(Prefs.token(this@WizardActivity))
        }
        pairStatus = TextView(this).apply {
            text = getString(R.string.wiz_pair_status_empty)
            textSize = 13f
            setTextColor(getColor(R.color.es_muted))
            setPadding(0, dp(8), 0, dp(8))
        }
        box.addView(urlEdit)
        box.addView(tokenEdit)
        box.addView(pairStatus)
    }

    private fun buildOptions(box: LinearLayout) {
        box.addView(title(getString(R.string.wiz_opts_title)))
        box.addView(body(getString(R.string.wiz_opts_body)))

        box.addView(
            switchRow(R.string.control_auto_sync, Prefs.autoSync(this), R.string.control_auto_sync_hint) { on ->
                Prefs.setAutoSync(this, on)
                SyncEngine.schedulePeriodic(this)
            }
        )
        box.addView(
            stepperRow(
                R.string.control_interval, Prefs.syncIntervalMin(this), 15, 15, 240,
                format = { getString(R.string.control_interval_value, it) }
            ) { value ->
                Prefs.setSyncIntervalMin(this, value)
                SyncEngine.schedulePeriodic(this)
            }
        )
        box.addView(
            switchRow(R.string.control_wifi_only, Prefs.wifiOnly(this), R.string.control_wifi_only_hint) { on ->
                Prefs.setWifiOnly(this, on)
            }
        )
        box.addView(
            switchRow(R.string.control_notifications, Prefs.notifications(this)) { on ->
                Prefs.setNotifications(this, on)
                if (!on) BridgeNotifier.cancel(this)
                else if (Prefs.keepAlive(this)) KeepAliveService.start(this)
            }
        )
        box.addView(
            switchRow(R.string.control_keepalive, Prefs.keepAlive(this), R.string.control_keepalive_hint) { on ->
                Prefs.setKeepAlive(this, on)
                if (on) KeepAliveService.start(this) else KeepAliveService.stop(this)
            }
        )
    }

    private fun buildGoals(box: LinearLayout) {
        box.addView(title(getString(R.string.wiz_goals_title)))
        box.addView(body(getString(R.string.wiz_goals_body)))
        box.addView(
            stepperRow(
                R.string.dash_steps, Prefs.stepsGoal(this), 500, 1000, 50000,
                format = { getString(R.string.control_goal_value, it) }
            ) { Prefs.setStepsGoal(this, it) }
        )
        box.addView(
            stepperRow(
                R.string.dash_kcal, Prefs.kcalGoal(this), 50, 100, 5000,
                format = { getString(R.string.control_goal_value, it) }
            ) { Prefs.setKcalGoal(this, it) }
        )
    }

    private fun buildDiagnostics(box: LinearLayout) {
        box.addView(title(getString(R.string.wiz_diag_title)))
        box.addView(body(getString(R.string.wiz_diag_body)))
        box.addView(
            switchRow(R.string.about_auto_report, Prefs.autoReport(this), R.string.about_auto_report_hint) { on ->
                Prefs.setAutoReport(this, on)
            }
        )
        val battery = TextView(this).apply {
            text = getString(
                if (BatteryHelper.isIgnoringBatteryOptimizations(this@WizardActivity)) R.string.control_battery_ok
                else R.string.control_battery
            )
            textSize = 14f
            setTextColor(getColor(R.color.es_text))
            setPadding(0, dp(12), 0, dp(4))
        }
        box.addView(battery)
        box.addView(secondaryButton(getString(R.string.control_battery)) {
            BatteryHelper.requestIgnoreBatteryOptimizations(this)
        })
    }

    private fun buildReady(box: LinearLayout) {
        box.addView(title(getString(R.string.wiz_done_title)))
        box.addView(body(getString(R.string.wiz_done_body)))
        box.addView(hintText(getString(R.string.wiz_summary_title)))
        box.addView(metricLine(getString(R.string.control_url), Prefs.url(this).ifBlank { "—" }))
        box.addView(
            metricLine(
                getString(R.string.control_interval),
                getString(R.string.control_interval_value, Prefs.syncIntervalMin(this))
            )
        )
        box.addView(metricLine(getString(R.string.dash_steps), getString(R.string.control_goal_value, Prefs.stepsGoal(this))))
        box.addView(metricLine(getString(R.string.dash_kcal), getString(R.string.control_goal_value, Prefs.kcalGoal(this))))

        finishStatus = TextView(this).apply {
            textSize = 13f
            setTextColor(getColor(R.color.es_muted))
            setPadding(0, dp(8), 0, dp(8))
        }
        box.addView(finishStatus)
        box.addView(primaryButton(getString(R.string.wiz_sync_btn)) {
            finishStatus?.text = getString(R.string.wiz_sync_running)
            lifecycleScope.launch {
                val outcome = SyncEngine.syncNow(this@WizardActivity, "wizard")
                finishStatus?.text = when {
                    outcome.deferred -> getString(R.string.dash_sync_deferred)
                    outcome.ok -> getString(R.string.wiz_sync_ok)
                    else -> getString(R.string.wiz_sync_fail, outcome.message)
                }
            }
        })
        box.addView(secondaryButton(getString(R.string.control_backfill)) {
            val days = Prefs.backfillDays(this)
            finishStatus?.text = getString(R.string.charts_loading)
            lifecycleScope.launch {
                val stored = SyncEngine.backfill(this@WizardActivity, days) { done ->
                    runOnUiThread {
                        finishStatus?.text = getString(R.string.charts_loading_progress, done, days)
                    }
                }
                finishStatus?.text = getString(R.string.diary_refresh_done) + " · $stored"
            }
        })
        box.addView(body(getString(R.string.wiz_done_battery)))
    }

    // ── Pairing ───────────────────────────────────────────────────────────────

    private fun requestHealthPermissions() {
        when (HealthConnectReader.sdkStatus(this)) {
            HealthConnectClient.SDK_AVAILABLE -> runCatching {
                permissionLauncher.launch(HealthConnectReader.PERMISSIONS)
            }.onFailure { Toast.makeText(this, R.string.err_hc_unavailable, Toast.LENGTH_LONG).show() }
            HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED -> openHealthConnect()
            else -> Toast.makeText(this, R.string.dash_hc_missing, Toast.LENGTH_LONG).show()
        }
    }

    private fun scanQr() {
        val options = ScanOptions().apply {
            setDesiredBarcodeFormats(ScanOptions.QR_CODE)
            setPrompt(getString(R.string.wiz_pair_scan))
            setBeepEnabled(false)
            setOrientationLocked(false)
        }
        runCatching { qrLauncher.launch(options) }.onFailure {
            Toast.makeText(this, R.string.err_generic, Toast.LENGTH_SHORT).show()
        }
    }

    private fun discover() {
        pairStatus?.text = getString(R.string.wiz_discovering)
        lifecycleScope.launch {
            val found = DiscoveryScanner.scan(this@WizardActivity)
            if (found.isEmpty()) {
                pairStatus?.text = getString(R.string.wiz_discover_none)
                return@launch
            }
            if (found.size == 1) {
                urlEdit?.setText(found[0].url)
                pairStatus?.text = found[0].url
            } else {
                AlertDialog.Builder(this@WizardActivity)
                    .setTitle(R.string.wiz_discover_pick)
                    .setItems(found.map { "${it.name} — ${it.url}" }.toTypedArray()) { _, which ->
                        urlEdit?.setText(found[which].url)
                        pairStatus?.text = found[which].url
                    }
                    .show()
            }
        }
    }

    private suspend fun savePairing(): Boolean {
        val url = urlEdit?.text?.toString()?.trim().orEmpty().ifBlank { Prefs.url(this) }
        val token = tokenEdit?.text?.toString()?.trim().orEmpty().ifBlank { Prefs.token(this) }
        if (url.isBlank() || token.isBlank()) {
            Toast.makeText(this, R.string.wiz_pair_need_both, Toast.LENGTH_SHORT).show()
            return false
        }
        if (!token.startsWith("es_health_")) {
            Toast.makeText(this, R.string.wiz_pair_bad_token, Toast.LENGTH_LONG).show()
            return false
        }
        pairStatus?.text = getString(R.string.wiz_pair_testing)
        val hello = EverShelfClient.hello(url)
        if (hello == null) {
            pairStatus?.text = getString(R.string.wiz_pair_unreachable)
            Toast.makeText(this, R.string.wiz_pair_unreachable, Toast.LENGTH_LONG).show()
            return false
        }
        Prefs.saveServer(this, hello.baseUrl, token)
        ServerReachability.markReachable()
        Logs.info(this, "wizard", "paired with ${hello.baseUrl} (${hello.name})")
        pairStatus?.text = getString(R.string.wiz_pair_ok, hello.name)
        return true
    }

    private fun applyPairingPayload(raw: String) {
        val json = runCatching { JSONObject(raw) }.getOrNull()
        val url = json?.optString("url", "").orEmpty().ifBlank {
            runCatching { Uri.parse(raw).getQueryParameter("url") }.getOrNull() ?: ""
        }
        val token = json?.optString("token", "").orEmpty().ifBlank {
            runCatching { Uri.parse(raw).getQueryParameter("token") }.getOrNull() ?: ""
        }
        if (url.isBlank() && token.isBlank()) return
        if (step != STEP_PAIRING) showStep(STEP_PAIRING)
        urlEdit?.setText(url)
        tokenEdit?.setText(token)
        pairStatus?.text = getString(R.string.wiz_pair_qr_ok)
    }

    private fun handleDeepLink(uri: Uri) {
        applyPairingPayload(uri.toString())
    }

    private fun openHealthConnect() {
        try {
            startActivity(Intent(Intent.ACTION_VIEW).setPackage("com.google.android.apps.healthdata"))
        } catch (_: Throwable) {
            runCatching {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=com.google.android.apps.healthdata")))
            }.onFailure { Toast.makeText(this, R.string.dash_hc_missing, Toast.LENGTH_SHORT).show() }
        }
    }

    // ── Small view builders ───────────────────────────────────────────────────

    private fun title(text: String): TextView = TextView(this).apply {
        this.text = text
        textSize = 24f
        typeface = Typeface.DEFAULT_BOLD
        setTextColor(getColor(R.color.es_text))
        setPadding(0, dp(2), 0, dp(8))
    }

    private fun body(text: String): TextView = TextView(this).apply {
        this.text = text
        textSize = 15f
        setTextColor(getColor(R.color.es_text_soft))
        setPadding(0, 0, 0, dp(10))
    }

    private fun hintText(text: String): TextView = TextView(this).apply {
        this.text = text
        textSize = 12f
        typeface = Typeface.DEFAULT_BOLD
        setTextColor(getColor(R.color.es_muted))
        setPadding(0, 0, 0, dp(6))
    }

    private fun metricLine(label: String, value: String): View {
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            setPadding(0, dp(3), 0, dp(3))
        }
        row.addView(TextView(this).apply {
            text = label
            textSize = 13f
            setTextColor(getColor(R.color.es_text_soft))
            layoutParams = LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f)
        })
        row.addView(TextView(this).apply {
            text = value
            textSize = 13f
            typeface = Typeface.DEFAULT_BOLD
            setTextColor(getColor(R.color.es_text))
        })
        return row
    }

    private fun switchRow(
        @StringRes label: Int,
        checked: Boolean,
        @StringRes hint: Int? = null,
        onChange: (Boolean) -> Unit,
    ): View {
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, dp(6), 0, 0)
        }
        box.addView(MaterialSwitch(this).apply {
            text = getString(label)
            textSize = 15f
            isChecked = checked
            setOnCheckedChangeListener { _, value -> onChange(value) }
        })
        if (hint != null) {
            box.addView(TextView(this).apply {
                text = getString(hint)
                textSize = 12f
                setTextColor(getColor(R.color.es_muted))
            })
        }
        return box
    }

    private fun stepperRow(
        @StringRes label: Int,
        initial: Int,
        step: Int,
        min: Int,
        max: Int,
        format: (Int) -> String,
        onChange: (Int) -> Unit,
    ): View {
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(0, dp(6), 0, dp(2))
        }
        row.addView(TextView(this).apply {
            text = getString(label)
            textSize = 15f
            setTextColor(getColor(R.color.es_text))
            layoutParams = LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f)
        })
        val value = TextView(this).apply {
            text = format(initial)
            textSize = 14f
            typeface = Typeface.DEFAULT_BOLD
            gravity = Gravity.CENTER
            setTextColor(getColor(R.color.es_text))
            minWidth = dp(84)
        }
        var current = initial.coerceIn(min, max)
        fun apply(next: Int) {
            current = next.coerceIn(min, max)
            value.text = format(current)
            onChange(current)
        }
        row.addView(compactButton("−") { apply(current - step) })
        row.addView(value)
        row.addView(compactButton("+") { apply(current + step) })
        return row
    }

    private fun primaryButton(label: String, onClick: () -> Unit): MaterialButton =
        MaterialButton(this).apply {
            text = label
            isAllCaps = false
            setOnClickListener { onClick() }
            layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(52))
                .apply { topMargin = dp(6) }
        }

    private fun secondaryButton(label: String, onClick: () -> Unit): MaterialButton =
        MaterialButton(this, null, com.google.android.material.R.attr.materialButtonOutlinedStyle).apply {
            text = label
            isAllCaps = false
            setOnClickListener { onClick() }
            layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, dp(46))
                .apply { topMargin = dp(4) }
        }

    private fun compactButton(label: String, onClick: () -> Unit): MaterialButton =
        MaterialButton(this, null, com.google.android.material.R.attr.materialButtonOutlinedStyle).apply {
            text = label
            isAllCaps = false
            insetTop = 0
            insetBottom = 0
            setOnClickListener { onClick() }
            layoutParams = LinearLayout.LayoutParams(dp(46), dp(44))
        }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    companion object {
        const val EXTRA_RECONFIGURE = "reconfigure"
        private const val KEY_STEP = "wizard_step"

        private const val STEP_LANGUAGE = 0
        private const val STEP_WELCOME = 1
        private const val STEP_PERMISSIONS = 2
        private const val STEP_PAIRING = 3
        private const val STEP_OPTIONS = 4
        private const val STEP_GOALS = 5
        private const val STEP_DIAGNOSTICS = 6
        private const val STEP_READY = 7
        private const val TOTAL_STEPS = 8
    }
}
