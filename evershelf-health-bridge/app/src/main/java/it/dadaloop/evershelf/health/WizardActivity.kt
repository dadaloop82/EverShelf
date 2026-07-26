package it.dadaloop.evershelf.health

import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Bundle
import android.view.Gravity
import android.view.View
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
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
 * First-run wizard (like EverShelf install):
 * 0 Language → 1 Welcome → 2 Health Connect → 3 QR pair → 4 First sync → 5 Done
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
    private var syncStatus: TextView? = null
    private var pairedOk = false

    private val permissionLauncher = registerForActivityResult(
        PermissionController.createRequestPermissionResultContract()
    ) { granted ->
        val msg = if (granted.containsAll(HealthConnectReader.PERMISSIONS)) {
            getString(R.string.wiz_perms_granted)
        } else {
            getString(R.string.wiz_perms_partial)
        }
        Toast.makeText(this, msg, Toast.LENGTH_SHORT).show()
        showStep(2)
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

        // Deep link / share: evershelfhealth://pair?... or raw JSON
        intent?.data?.let { handleDeepLink(it) }
        intent?.getStringExtra(EXTRA_PAIRING)?.let { applyPairingPayload(it) }

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
        val next = step + delta
        when {
            delta > 0 && step == 3 -> {
                // validate + save before leaving pair step
                lifecycleScope.launch { if (savePairing()) showStep(4) }
            }
            delta > 0 && step == 5 -> {
                startActivity(Intent(this, MainActivity::class.java))
                finish()
            }
            next in 0..5 -> showStep(next)
        }
    }

    private fun showStep(s: Int) {
        step = s
        renderDots()
        container.removeAllViews()
        btnBack.visibility = if (s == 0) View.INVISIBLE else View.VISIBLE
        btnNext.text = when (s) {
            5 -> getString(R.string.btn_finish)
            3 -> getString(R.string.btn_next)
            else -> getString(R.string.btn_next)
        }
        when (s) {
            0 -> renderLanguage()
            1 -> renderWelcome()
            2 -> renderPermissions()
            3 -> renderPair()
            4 -> renderSync()
            5 -> renderDone()
        }
    }

    private fun renderDots() {
        dots.removeAllViews()
        // language step has no dots; steps 1..5
        if (step == 0) return
        for (i in 1..5) {
            val d = View(this)
            val size = (10 * resources.displayMetrics.density).toInt()
            val lp = LinearLayout.LayoutParams(size, size).apply {
                marginStart = 6; marginEnd = 6
            }
            d.layoutParams = lp
            d.background = GradientDrawable().apply {
                shape = GradientDrawable.OVAL
                setColor(if (i == step) Color.parseColor("#2D5016") else Color.parseColor("#C5D6B0"))
            }
            dots.addView(d)
        }
    }

    private fun title(text: String): TextView = TextView(this).apply {
        this.text = text
        textSize = 24f
        setTextColor(Color.parseColor("#1A2E0A"))
        setTypeface(typeface, android.graphics.Typeface.BOLD)
    }

    private fun body(text: String): TextView = TextView(this).apply {
        this.text = text
        textSize = 15f
        setTextColor(Color.parseColor("#5C6B52"))
        setPadding(0, 12, 0, 12)
        setLineSpacing(4f, 1f)
    }

    private fun col(vararg views: View): LinearLayout = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        layoutParams = FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT,
            FrameLayout.LayoutParams.MATCH_PARENT
        )
        views.forEach { addView(it) }
    }

    private fun renderLanguage() {
        val row = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        val langs = listOf(
            "it" to "Italiano",
            "en" to "English",
            "de" to "Deutsch",
            "fr" to "Français",
            "es" to "Español",
        )
        langs.forEach { (code, label) ->
            val b = MaterialButton(this).apply {
                text = label
                isAllCaps = false
                setOnClickListener {
                    LocaleHelper.setLanguage(this@WizardActivity, code)
                    recreate()
                }
            }
            row.addView(b)
        }
        container.addView(col(title(getString(R.string.wiz_lang_title)), body(getString(R.string.wiz_lang_hint)), row))
        btnNext.setOnClickListener {
            if (LocaleHelper.language(this).isBlank()) LocaleHelper.setLanguage(this, "en")
            showStep(1)
        }
    }

    private fun renderWelcome() {
        container.addView(
            col(
                title(getString(R.string.wiz_welcome_title)),
                body(getString(R.string.wiz_welcome_body)),
                body(getString(R.string.privacy_policy))
            )
        )
        btnNext.setOnClickListener { showStep(2) }
    }

    private fun renderPermissions() {
        val grant = MaterialButton(this).apply {
            text = getString(R.string.wiz_perms_btn)
            isAllCaps = false
            setOnClickListener { requestHc() }
        }
        val store = MaterialButton(this, null, com.google.android.material.R.attr.materialButtonOutlinedStyle).apply {
            text = getString(R.string.wiz_perms_open_store)
            isAllCaps = false
            setOnClickListener { openHealthConnect() }
        }
        container.addView(
            col(
                title(getString(R.string.wiz_perms_title)),
                body(getString(R.string.wiz_perms_body)),
                grant,
                store
            )
        )
        btnNext.setOnClickListener { showStep(3) }
    }

    private fun renderPair() {
        pairStatus = TextView(this).apply {
            text = getString(R.string.wiz_pair_status_empty)
            setTextColor(Color.parseColor("#5C6B52"))
            setPadding(0, 8, 0, 8)
        }
        val scan = MaterialButton(this).apply {
            text = getString(R.string.wiz_pair_scan)
            isAllCaps = false
            setOnClickListener {
                qrLauncher.launch(
                    ScanOptions()
                        .setDesiredBarcodeFormats(ScanOptions.QR_CODE)
                        .setPrompt(getString(R.string.wiz_pair_scan))
                        .setBeepEnabled(false)
                )
            }
        }
        val discover = MaterialButton(this, null, com.google.android.material.R.attr.materialButtonOutlinedStyle).apply {
            text = getString(R.string.wiz_pair_discover)
            isAllCaps = false
            setOnClickListener { runDiscovery() }
        }
        urlEdit = EditText(this).apply {
            hint = getString(R.string.wiz_pair_url_hint)
            setText(Prefs.url(this@WizardActivity))
            setSingleLine()
        }
        tokenEdit = EditText(this).apply {
            hint = getString(R.string.wiz_pair_token_hint)
            setText(Prefs.token(this@WizardActivity))
            setSingleLine()
        }
        val manualLabel = TextView(this).apply {
            text = getString(R.string.wiz_pair_manual)
            setPadding(0, 16, 0, 8)
            setTextColor(Color.parseColor("#5C6B52"))
        }
        container.addView(
            col(
                title(getString(R.string.wiz_pair_title)),
                body(getString(R.string.wiz_pair_body)),
                scan,
                discover,
                pairStatus!!,
                manualLabel,
                urlEdit!!,
                tokenEdit!!
            )
        )
        btnNext.setOnClickListener {
            lifecycleScope.launch { if (savePairing()) showStep(4) }
        }
    }

    private fun renderSync() {
        syncStatus = TextView(this).apply {
            text = ""
            setPadding(0, 12, 0, 12)
        }
        val syncBtn = MaterialButton(this).apply {
            text = getString(R.string.wiz_sync_btn)
            isAllCaps = false
            setOnClickListener {
                text = getString(R.string.wiz_sync_running)
                isEnabled = false
                lifecycleScope.launch {
                    val res = SyncHelper.syncNow(this@WizardActivity)
                    isEnabled = true
                    text = getString(R.string.wiz_sync_btn)
                    syncStatus?.text = if (res.isSuccess) {
                        getString(R.string.wiz_sync_ok)
                    } else {
                        getString(R.string.wiz_sync_fail, res.exceptionOrNull()?.message ?: "?")
                    }
                }
            }
        }
        container.addView(
            col(
                title(getString(R.string.wiz_sync_title)),
                body(getString(R.string.wiz_sync_body)),
                syncBtn,
                syncStatus!!
            )
        )
        btnNext.setOnClickListener { showStep(5) }
    }

    private fun renderDone() {
        container.addView(
            col(
                title(getString(R.string.wiz_done_title)),
                body(getString(R.string.wiz_done_body)),
                body(getString(R.string.wiz_done_battery))
            )
        )
        SyncHelper.schedulePeriodic(this)
        BatteryHelper.ensureBackgroundSurvival(this)
        btnNext.setOnClickListener {
            BatteryHelper.ensureBackgroundSurvival(this)
            startActivity(Intent(this, MainActivity::class.java))
            finish()
        }
    }

    private fun applyPairingPayload(raw: String) {
        val text = raw.trim()
        try {
            val j = when {
                text.startsWith("{") -> JSONObject(text)
                text.contains("url=") || text.startsWith("evershelfhealth:") -> {
                    val uri = Uri.parse(text.replace("evershelfhealth://pair?", "https://x/?"))
                    val data = uri.getQueryParameter("data") ?: uri.getQueryParameter("payload")
                    if (data != null) JSONObject(String(android.util.Base64.decode(data, android.util.Base64.URL_SAFE)))
                    else JSONObject().put("url", uri.getQueryParameter("url")).put("token", uri.getQueryParameter("token"))
                }
                else -> throw IllegalArgumentException("not json")
            }
            val url = j.optString("url")
            val token = j.optString("token")
            if (url.isNotBlank()) urlEdit?.setText(url)
            if (token.isNotBlank()) tokenEdit?.setText(token)
            // If edits not built yet (deep link before pair step), save prefs
            if (url.isNotBlank() && token.isNotBlank()) {
                Prefs.saveServer(this, url, token)
                pairedOk = true
            }
            pairStatus?.text = getString(R.string.wiz_pair_qr_ok)
            Toast.makeText(this, R.string.wiz_pair_qr_ok, Toast.LENGTH_SHORT).show()
            if (step != 3) showStep(3)
        } catch (_: Exception) {
            if (text.startsWith("es_health_")) {
                tokenEdit?.setText(text)
            } else if (text.startsWith("http")) {
                urlEdit?.setText(text)
            } else {
                Toast.makeText(this, R.string.wiz_pair_need_both, Toast.LENGTH_SHORT).show()
            }
        }
    }

    private fun handleDeepLink(uri: Uri) {
        val data = uri.getQueryParameter("data") ?: uri.getQueryParameter("payload")
        when {
            data != null -> {
                try {
                    applyPairingPayload(String(android.util.Base64.decode(data, android.util.Base64.URL_SAFE or android.util.Base64.NO_WRAP)))
                } catch (_: Exception) {
                    applyPairingPayload(data)
                }
            }
            uri.getQueryParameter("url") != null -> {
                applyPairingPayload(
                    JSONObject()
                        .put("url", uri.getQueryParameter("url"))
                        .put("token", uri.getQueryParameter("token") ?: "")
                        .toString()
                )
            }
            else -> applyPairingPayload(uri.toString())
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
        pairedOk = true
        pairStatus?.text = getString(R.string.wiz_pair_ok, hello.name)
        Toast.makeText(this, getString(R.string.wiz_pair_ok, hello.name), Toast.LENGTH_SHORT).show()
        return true
    }

    private fun runDiscovery() {
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

    private fun requestHc() {
        val sdk = HealthConnectReader.sdkStatus(this)
        when (sdk) {
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

    companion object {
        const val EXTRA_PAIRING = "pairing_payload"
    }
}
