package it.dadaloop.evershelf.health

import android.content.Context
import android.content.Intent
import android.graphics.Typeface
import android.net.Uri
import android.os.Bundle
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.launch
import org.json.JSONObject

/**
 * First run: language → welcome → Health Connect → pairing → done.
 *
 * Five steps, one screen each, and nothing is written until the pairing really answers
 * (`health_bridge_hello`), so "paired" always means "reachable".
 */
class WizardActivity : AppCompatActivity() {

    private var step = 0
    private lateinit var container: FrameLayout
    private lateinit var dots: LinearLayout
    private lateinit var btnBack: MaterialButton
    private lateinit var btnNext: MaterialButton

    private var urlEdit: EditText? = null
    private var tokenEdit: EditText? = null
    private var pairStatus: TextView? = null

    private val permissionLauncher = registerForActivityResult(
        PermissionController.createRequestPermissionResultContract()
    ) { granted ->
        val ok = granted.containsAll(HealthConnectReader.PERMISSIONS)
        Toast.makeText(
            this,
            if (ok) R.string.wiz_perms_granted else R.string.wiz_perms_partial,
            Toast.LENGTH_SHORT
        ).show()
        showStep(3)
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

        intent?.data?.let { handleDeepLink(it) }

        showStep(if (LocaleHelper.language(this).isBlank()) 0 else 1)
    }

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
                3 -> {
                    btnNext.isEnabled = false
                    lifecycleScope.launch {
                        val ok = savePairing()
                        btnNext.isEnabled = true
                        if (ok) showStep(4)
                    }
                    return
                }
                4 -> {
                    finishSetup()
                    return
                }
            }
        }
        showStep((step + delta).coerceIn(0, 4))
    }

    private fun finishSetup() {
        if (!Prefs.isConfigured(this)) {
            showStep(3)
            return
        }
        BatteryHelper.requestNotificationPermission(this)
        SyncEngine.schedulePeriodic(this)
        if (Prefs.keepAlive(this)) KeepAliveService.start(this)
        SyncEngine.requestImmediate(this)
        Logs.info(this, "wizard", "setup finished — ${Prefs.url(this)}")
        startActivity(Intent(this, MainActivity::class.java))
        finish()
    }

    private fun showStep(next: Int) {
        step = next
        container.removeAllViews()
        urlEdit = null
        tokenEdit = null
        pairStatus = null
        container.addView(buildStep(next))
        paintDots(next)
        btnBack.visibility = if (next == 0) View.INVISIBLE else View.VISIBLE
        btnNext.setText(if (next == 4) R.string.btn_finish else R.string.btn_next)
    }

    private fun paintDots(active: Int) {
        dots.removeAllViews()
        for (i in 0..4) {
            val dot = View(this)
            val size = (9 * resources.displayMetrics.density).toInt()
            val params = LinearLayout.LayoutParams(size, size)
            params.marginEnd = (7 * resources.displayMetrics.density).toInt()
            dot.layoutParams = params
            dot.setBackgroundResource(if (i == active) R.drawable.bg_chip_mint else R.drawable.bg_chip_neutral)
            dots.addView(dot)
        }
    }

    private fun buildStep(index: Int): View {
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.TOP
        }
        when (index) {
            0 -> {
                box.addView(title(getString(R.string.wiz_lang_title)))
                box.addView(body(getString(R.string.wiz_lang_hint)))
                val languages = listOf(
                    "it" to "Italiano", "en" to "English", "de" to "Deutsch",
                    "fr" to "Français", "es" to "Español", "zh" to "中文"
                )
                for ((code, label) in languages) {
                    box.addView(primaryButton(label) {
                        LocaleHelper.setLanguage(this, code)
                        recreate()
                    })
                }
                btnNext.visibility = View.GONE
            }
            1 -> {
                box.addView(title(getString(R.string.wiz_welcome_title)))
                box.addView(body(getString(R.string.wiz_welcome_body)))
                btnNext.visibility = View.VISIBLE
            }
            2 -> {
                box.addView(title(getString(R.string.wiz_perms_title)))
                box.addView(body(getString(R.string.wiz_perms_body)))
                box.addView(primaryButton(getString(R.string.wiz_perms_btn)) { requestHealthPermissions() })
                box.addView(secondaryButton(getString(R.string.wiz_perms_open_store)) { openHealthConnect() })
                btnNext.visibility = View.VISIBLE
            }
            3 -> {
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
                btnNext.visibility = View.VISIBLE
            }
            else -> {
                box.addView(title(getString(R.string.wiz_done_title)))
                box.addView(body(getString(R.string.wiz_done_body)))
                box.addView(body(getString(R.string.wiz_done_battery)))
                btnNext.visibility = View.VISIBLE
            }
        }
        return box
    }

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
        if (step != 3) showStep(3)
        urlEdit?.setText(url)
        tokenEdit?.setText(token)
        pairStatus?.text = getString(R.string.wiz_pair_qr_ok)
    }

    private fun handleDeepLink(uri: Uri) {
        if (uri.scheme == "evershelfhealth") {
            applyPairingPayload(uri.toString())
        } else {
            applyPairingPayload(uri.toString())
        }
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

    private fun title(text: String): TextView = TextView(this).apply {
        this.text = text
        textSize = 24f
        typeface = Typeface.DEFAULT_BOLD
        setTextColor(getColor(R.color.es_text))
        setPadding(0, 0, 0, dp(8))
    }

    private fun body(text: String): TextView = TextView(this).apply {
        this.text = text
        textSize = 15f
        setTextColor(getColor(R.color.es_text_soft))
        setPadding(0, 0, 0, dp(10))
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

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}
