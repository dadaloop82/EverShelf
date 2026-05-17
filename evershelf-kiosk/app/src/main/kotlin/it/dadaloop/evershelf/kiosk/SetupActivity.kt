package it.dadaloop.evershelf.kiosk

import android.Manifest
import android.app.AlertDialog
import android.bluetooth.BluetoothDevice
import android.content.Context
import android.content.Intent
import android.content.SharedPreferences
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.google.android.material.button.MaterialButton
import com.google.android.material.switchmaterial.SwitchMaterial
import it.dadaloop.evershelf.kiosk.scale.BleDeviceInfo
import it.dadaloop.evershelf.kiosk.scale.BleScaleListener
import it.dadaloop.evershelf.kiosk.scale.BleScaleManager
import it.dadaloop.evershelf.kiosk.scale.WeightReading
import java.net.HttpURLConnection
import java.net.InetSocketAddress
import java.net.NetworkInterface
import java.net.Socket
import java.net.URL
import java.util.Locale
import java.util.concurrent.ExecutorCompletionService
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicInteger
import java.util.concurrent.atomic.AtomicLong
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager

/**
 * Full setup wizard — runs BEFORE KioskActivity locks the screen.
 * The user can always exit (finishAffinity) via the ✕ button.
 *
 * Steps:
 *  0 — Language selection (NEW — always first)
 *  1 — Welcome / intro / privacy
 *  2 — Permissions rationale + grant
 *  3 — Server URL + auto-discovery + connection test
 *  4 — Smart scale question → gateway info + install
 *  5 — Screensaver toggle (NEW)
 *  6 — Done
 */
class SetupActivity : AppCompatActivity() {

    private lateinit var prefs: SharedPreferences
    private var currentStep = 0

    // Step containers
    private lateinit var stepLanguage:    LinearLayout
    private lateinit var stepWelcome:     LinearLayout
    private lateinit var stepPermissions: LinearLayout
    private lateinit var stepServer:      LinearLayout
    private lateinit var stepScale:       LinearLayout
    private lateinit var stepScreensaver: LinearLayout
    private lateinit var stepDone:        LinearLayout

    // Progress dots
    private lateinit var progressDots: LinearLayout

    // Server step
    private lateinit var urlEdit:        EditText
    private lateinit var urlStatus:      TextView
    private lateinit var btnTestUrl:     MaterialButton
    private lateinit var btnDiscover:    MaterialButton
    private lateinit var discoverStatus: TextView

    // Scale step (BLE)
    private lateinit var scaleQuestionCard: LinearLayout
    private lateinit var scalePowerOnCard:  LinearLayout
    private lateinit var bleSetupCard:      LinearLayout
    private lateinit var scaleTestCard:     LinearLayout
    private lateinit var tvScanStatus:      TextView
    private lateinit var btnScanBle:        MaterialButton
    private lateinit var tvSelectedScale:   TextView
    private lateinit var rvScaleDevices:    RecyclerView
    private lateinit var step3NextButtons:  LinearLayout
    private lateinit var tvTestStatus:      TextView
    private lateinit var tvTestWeight:      TextView
    private lateinit var testWeightBox:     android.widget.LinearLayout

    private var bleManager: BleScaleManager? = null
    private val discoveredDevices = mutableListOf<BleDeviceInfo>()
    private var selectedDevice: BleDeviceInfo? = null
    private var deviceAdapter: DeviceAdapter? = null

    // Test-mode state
    private var isInTestMode = false
    private var testHasWeight = false

    // Screensaver step
    private lateinit var setupSwitchScreensaver: SwitchMaterial
    private lateinit var setupSwitchPrices:      SwitchMaterial
    private lateinit var setupSwitchMealPlan:    SwitchMaterial
    private lateinit var setupSwitchZeroWaste:   SwitchMaterial

    // Done step
    private lateinit var summaryText: TextView

    // Permissions step
    private lateinit var permsGrantedCard: LinearLayout
    private lateinit var btnGrantPerms:    MaterialButton

    // Auto-discover cancellation flag
    private val discoverCancelled = AtomicBoolean(false)

    companion object {
        private const val PREFS_NAME         = "evershelf_kiosk"
        private const val KEY_URL            = "evershelf_url"
        private const val KEY_SETUP_COMPLETE = "setup_complete"
        private const val KEY_HAS_SCALE      = "has_scale"
        private const val KEY_LANGUAGE       = "kiosk_language"
        private const val KEY_SCREENSAVER    = "screensaver_enabled"
        private const val KEY_PRICE_ENABLED  = "price_enabled"
        private const val KEY_MEAL_PLAN      = "meal_plan_enabled"
        private const val KEY_ZEROWASTE_TIPS = "zerowaste_tips_enabled"
        private const val PERMISSION_REQUEST_CODE = 2004
        private const val BLE_PERMISSION_REQUEST  = 2006

        fun applyLocale(base: Context, lang: String): Context {
            val locale = Locale(lang)
            Locale.setDefault(locale)
            val config = Configuration(base.resources.configuration)
            config.setLocale(locale)
            return base.createConfigurationContext(config)
        }
    }

    // ── Locale wrapping ───────────────────────────────────────────────────

    override fun attachBaseContext(newBase: Context) {
        val lang = newBase.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(KEY_LANGUAGE, null)
        super.attachBaseContext(if (lang != null) applyLocale(newBase, lang) else newBase)
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_setup)
        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        // Init ErrorReporter immediately using any previously saved URL (so install
        // errors during setup are reported even before the user confirms the URL)
        val savedUrl = prefs.getString(KEY_URL, "") ?: ""
        if (savedUrl.isNotEmpty()) ErrorReporter.init(this, savedUrl)
        bindViews()
        // Restore step from instance state (e.g. after recreate() for locale change)
        val savedStep = savedInstanceState?.getInt("step", -1) ?: -1
        val startStepExtra = intent.getIntExtra("start_step", -1)
        val langAlreadySet = prefs.getString(KEY_LANGUAGE, null) != null
        showStep(when {
            savedStep >= 0    -> savedStep
            startStepExtra >= 0 -> startStepExtra
            langAlreadySet    -> 1
            else              -> 0
        })
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putInt("step", currentStep)
    }

    override fun onBackPressed() {
        when (currentStep) {
            0 -> confirmExit()
            1 -> showStep(0)  // back to language
            else -> showStep(currentStep - 1)
        }
    }

    override fun onDestroy() {
        bleManager?.stopScan()
        bleManager?.disconnect()
        isInTestMode = false
        discoverCancelled.set(true)
        super.onDestroy()
    }

    override fun onResume() {
        super.onResume()
        // If we're on step 4 with a saved device and not in test mode, reflect it in the UI
        if (currentStep == 4 && !isInTestMode) {
            val savedName = bleManager?.getSavedDeviceName()
            if (savedName != null) {
                tvSelectedScale.text = "✅ $savedName"
                tvSelectedScale.visibility = View.VISIBLE
                findViewById<MaterialButton>(R.id.btnScaleNext).isEnabled = true
            }
        }
    }

    // ── Binding ────────────────────────────────────────────────────────────

    private fun bindViews() {
        progressDots     = findViewById(R.id.setupProgressDots)
        stepLanguage     = findViewById(R.id.stepLanguage)
        stepWelcome      = findViewById(R.id.stepWelcome)
        stepPermissions  = findViewById(R.id.stepPermissions)
        stepServer       = findViewById(R.id.stepServer)
        stepScale        = findViewById(R.id.stepScale)
        stepScreensaver  = findViewById(R.id.stepScreensaver)
        stepDone         = findViewById(R.id.stepDone)

        // Server step
        urlEdit        = findViewById(R.id.setupUrlEdit)
        urlStatus      = findViewById(R.id.setupUrlStatus)
        btnTestUrl     = findViewById(R.id.btnSetupTestUrl)
        btnDiscover    = findViewById(R.id.btnDiscover)
        discoverStatus = findViewById(R.id.discoverStatus)

        // Scale step
        scaleQuestionCard  = findViewById(R.id.scaleQuestionCard)
        scalePowerOnCard   = findViewById(R.id.scalePowerOnCard)
        bleSetupCard       = findViewById(R.id.bleSetupCard)
        scaleTestCard      = findViewById(R.id.scaleTestCard)
        tvScanStatus       = findViewById(R.id.tvScanStatus)
        btnScanBle         = findViewById(R.id.btnScanBle)
        tvSelectedScale    = findViewById(R.id.tvSelectedScale)
        rvScaleDevices     = findViewById(R.id.rvScaleDevices)
        step3NextButtons   = findViewById(R.id.step3NextButtons)
        tvTestStatus       = findViewById(R.id.tvTestStatus)
        tvTestWeight       = findViewById(R.id.tvTestWeight)
        testWeightBox      = findViewById(R.id.testWeightBox)

        // Features step — bind all four toggles
        setupSwitchScreensaver = findViewById(R.id.setupSwitchScreensaver)
        setupSwitchPrices      = findViewById(R.id.setupSwitchPrices)
        setupSwitchMealPlan    = findViewById(R.id.setupSwitchMealPlan)
        setupSwitchZeroWaste   = findViewById(R.id.setupSwitchZeroWaste)
        // Pre-fill from saved prefs only if each key was previously configured
        // ("se non sono impostati, chiedi!" — fresh install → all start at false)
        setupSwitchScreensaver.isChecked = if (prefs.contains(KEY_SCREENSAVER))  prefs.getBoolean(KEY_SCREENSAVER,  false) else false
        setupSwitchPrices.isChecked      = if (prefs.contains(KEY_PRICE_ENABLED)) prefs.getBoolean(KEY_PRICE_ENABLED, false) else false
        setupSwitchMealPlan.isChecked    = if (prefs.contains(KEY_MEAL_PLAN))    prefs.getBoolean(KEY_MEAL_PLAN,    false) else false
        setupSwitchZeroWaste.isChecked   = if (prefs.contains(KEY_ZEROWASTE_TIPS)) prefs.getBoolean(KEY_ZEROWASTE_TIPS, false) else false

        // Done step
        summaryText = findViewById(R.id.setupSummaryText)

        // Permissions step
        permsGrantedCard  = findViewById(R.id.permsGrantedCard)
        btnGrantPerms     = findViewById(R.id.btnGrantPerms)

        // Pre-fill saved URL
        val savedUrl = prefs.getString(KEY_URL, "") ?: ""
        if (savedUrl.isNotEmpty()) urlEdit.setText(savedUrl)

        // ── Language ─────────────────────────────────────────────────────
        // Highlight already-selected language button
        highlightSelectedLang()
        findViewById<MaterialButton>(R.id.btnLangIt).setOnClickListener { selectLanguage("it") }
        findViewById<MaterialButton>(R.id.btnLangEn).setOnClickListener { selectLanguage("en") }
        findViewById<MaterialButton>(R.id.btnLangDe).setOnClickListener { selectLanguage("de") }

        // ── Welcome ──────────────────────────────────────────────────────
        findViewById<MaterialButton>(R.id.btnSetupExit).setOnClickListener { confirmExit() }
        findViewById<MaterialButton>(R.id.btnWelcomeStart).setOnClickListener { showStep(2) }

        // ── Permissions ──────────────────────────────────────────────────
        btnGrantPerms.setOnClickListener { requestPermissions() }
        findViewById<MaterialButton>(R.id.btnPermsBack).setOnClickListener   { showStep(1) }
        findViewById<MaterialButton>(R.id.btnPermsNext).setOnClickListener   { showStep(3) }

        // ── Server ───────────────────────────────────────────────────────
        btnDiscover.setOnClickListener { autoDiscover() }
        btnTestUrl.setOnClickListener  { testConnection() }
        findViewById<MaterialButton>(R.id.btnServerBack).setOnClickListener { showStep(2) }
        findViewById<MaterialButton>(R.id.btnServerNext).setOnClickListener {
            val url = urlEdit.text.toString().trim()
            if (url.isEmpty()) {
                showUrlStatus(getString(R.string.setup_enter_url), false)
                return@setOnClickListener
            }
            prefs.edit().putString(KEY_URL, url).apply()
            ErrorReporter.init(this, url)
            showStep(4)
        }

        // ── Scale ─────────────────────────────────────────────────────────
        // Init BLE manager (lazy — needs context)
        bleManager = BleScaleManager(this, makeBleListener())
        // RecyclerView for discovered devices
        deviceAdapter = DeviceAdapter { info -> onDeviceSelected(info) }
        rvScaleDevices.layoutManager = LinearLayoutManager(this)
        rvScaleDevices.adapter = deviceAdapter

        findViewById<MaterialButton>(R.id.btnScaleYes).setOnClickListener {
            prefs.edit().putBoolean(KEY_HAS_SCALE, true).apply()
            scaleQuestionCard.visibility = View.GONE
            // Show power-on instruction first
            scalePowerOnCard.visibility  = View.VISIBLE
        }

        // "Bilancia accesa — Cerca" button
        findViewById<MaterialButton>(R.id.btnPowerOnReady).setOnClickListener {
            scalePowerOnCard.visibility  = View.GONE
            bleSetupCard.visibility      = View.VISIBLE
            step3NextButtons.visibility  = View.VISIBLE
            // Disable Next until device confirmed
            val savedName = bleManager?.getSavedDeviceName()
            if (savedName != null) {
                tvSelectedScale.text       = "✅ $savedName"
                tvSelectedScale.visibility = View.VISIBLE
                findViewById<MaterialButton>(R.id.btnScaleNext).isEnabled = true
            } else {
                findViewById<MaterialButton>(R.id.btnScaleNext).isEnabled = false
                startBleScan()
            }
        }
        findViewById<MaterialButton>(R.id.btnScaleNo).setOnClickListener {
            prefs.edit().putBoolean(KEY_HAS_SCALE, false).apply()
            bleManager?.stopScan()
            showStep(5)
        }
        btnScanBle.setOnClickListener { startBleScan() }
        findViewById<MaterialButton>(R.id.btnScaleBack).setOnClickListener {
            bleManager?.stopScan()
            bleManager?.disconnect()
            isInTestMode = false
            showStep(3)
        }
        findViewById<MaterialButton>(R.id.btnScaleNext).setOnClickListener {
            bleManager?.stopScan()
            bleManager?.disconnect()
            showStep(5)
        }

        // Test card buttons
        findViewById<MaterialButton>(R.id.btnTestConfirm).setOnClickListener {
            // User confirms weight matches → scale is good, proceed
            isInTestMode = false
            bleManager?.stopScan()
            bleManager?.disconnect()
            scaleTestCard.visibility = View.GONE
            bleSetupCard.visibility  = View.GONE
            step3NextButtons.visibility = View.VISIBLE
            val name = selectedDevice?.name ?: bleManager?.getSavedDeviceName() ?: "Bilancia"
            tvSelectedScale.text       = "✅ $name"
            tvSelectedScale.visibility = View.VISIBLE
            findViewById<MaterialButton>(R.id.btnScaleNext).isEnabled = true
            // Put scan card back (hidden) and show only nav buttons
            bleSetupCard.visibility = View.GONE
        }
        findViewById<MaterialButton>(R.id.btnTestRetry).setOnClickListener {
            // User says weight doesn't match → disconnect, go back to scan
            isInTestMode = false
            testHasWeight = false
            bleManager?.disconnect()
            selectedDevice = null
            bleManager?.clearSavedDevice()
            scaleTestCard.visibility   = View.GONE
            testWeightBox.visibility   = View.GONE
            bleSetupCard.visibility    = View.VISIBLE
            tvSelectedScale.text       = ""
            tvSelectedScale.visibility = View.GONE
            tvScanStatus.text          = "Bilancia non confermata. Riprova la scansione."
            tvScanStatus.setTextColor(0xFFfbbf24.toInt())
            btnScanBle.isEnabled       = true
            btnScanBle.text            = "🔍  Cerca bilancia"
            findViewById<MaterialButton>(R.id.btnScaleNext).isEnabled = false
        }
        findViewById<MaterialButton>(R.id.btnTestSkip).setOnClickListener {
            // User skips test — trust the selection
            isInTestMode = false
            bleManager?.disconnect()
            scaleTestCard.visibility = View.GONE
            bleSetupCard.visibility  = View.GONE
            step3NextButtons.visibility = View.VISIBLE
            val name = selectedDevice?.name ?: bleManager?.getSavedDeviceName() ?: "Bilancia"
            tvSelectedScale.text       = "✅ $name"
            tvSelectedScale.visibility = View.VISIBLE
            findViewById<MaterialButton>(R.id.btnScaleNext).isEnabled = true
        }

        // ── Features step (screensaver / prices / meal plan / zero-waste) ────
        findViewById<MaterialButton>(R.id.btnScreensaverBack).setOnClickListener { showStep(4) }
        findViewById<MaterialButton>(R.id.btnScreensaverNext).setOnClickListener {
            prefs.edit()
                .putBoolean(KEY_SCREENSAVER,  setupSwitchScreensaver.isChecked)
                .putBoolean(KEY_PRICE_ENABLED, setupSwitchPrices.isChecked)
                .putBoolean(KEY_MEAL_PLAN,     setupSwitchMealPlan.isChecked)
                .putBoolean(KEY_ZEROWASTE_TIPS, setupSwitchZeroWaste.isChecked)
                .apply()
            showStep(6)
        }

        // ── Done ──────────────────────────────────────────────────────────
        findViewById<MaterialButton>(R.id.btnLaunch).setOnClickListener { finishSetup() }
    }

    // ── Language selection ────────────────────────────────────────────────

    private fun selectLanguage(lang: String) {
        prefs.edit().putString(KEY_LANGUAGE, lang).apply()
        // Save step=1 so after recreate we land on Welcome
        currentStep = 1
        recreate()
    }

    private fun highlightSelectedLang() {
        val saved = prefs.getString(KEY_LANGUAGE, null) ?: return
        val (btnIt, btnEn, btnDe) = Triple(
            findViewById<MaterialButton>(R.id.btnLangIt),
            findViewById<MaterialButton>(R.id.btnLangEn),
            findViewById<MaterialButton>(R.id.btnLangDe)
        )
        // Add checkmark to selected
        btnIt.text = if (saved == "it") "✅  🇮🇹   Italiano" else "🇮🇹   Italiano"
        btnEn.text = if (saved == "en") "✅  🇬🇧   English"  else "🇬🇧   English"
        btnDe.text = if (saved == "de") "✅  🇩🇪   Deutsch"  else "🇩🇪   Deutsch"
    }

    // ── Step navigation ───────────────────────────────────────────────────

    private fun showStep(step: Int) {
        currentStep = step
        stepLanguage.visibility    = if (step == 0) View.VISIBLE else View.GONE
        stepWelcome.visibility     = if (step == 1) View.VISIBLE else View.GONE
        stepPermissions.visibility = if (step == 2) View.VISIBLE else View.GONE
        stepServer.visibility      = if (step == 3) View.VISIBLE else View.GONE
        stepScale.visibility       = if (step == 4) View.VISIBLE else View.GONE
        stepScreensaver.visibility = if (step == 5) View.VISIBLE else View.GONE
        stepDone.visibility        = if (step == 6) View.VISIBLE else View.GONE

        updateProgressDots()

        // Reset scale step when entering it
        if (step == 4) {
            isInTestMode = false
            testHasWeight = false
            scaleTestCard.visibility  = View.GONE
            testWeightBox.visibility  = View.GONE
            val hasScaleYes = prefs.contains(KEY_HAS_SCALE) && prefs.getBoolean(KEY_HAS_SCALE, false)
            if (hasScaleYes) {
                // Already said YES — skip power-on card, go straight to BLE scan
                scaleQuestionCard.visibility = View.GONE
                scalePowerOnCard.visibility  = View.GONE
                bleSetupCard.visibility      = View.VISIBLE
                step3NextButtons.visibility  = View.VISIBLE
                val savedName = bleManager?.getSavedDeviceName()
                if (savedName != null) {
                    tvSelectedScale.text       = "✅ $savedName"
                    tvSelectedScale.visibility = View.VISIBLE
                    findViewById<MaterialButton>(R.id.btnScaleNext).isEnabled = true
                } else {
                    tvSelectedScale.visibility = View.GONE
                    findViewById<MaterialButton>(R.id.btnScaleNext).isEnabled = false
                    startBleScan()
                }
            } else {
                scaleQuestionCard.visibility = View.VISIBLE
                scalePowerOnCard.visibility  = View.GONE
                bleSetupCard.visibility      = View.GONE
                step3NextButtons.visibility  = View.GONE
            }
        }

        // Build summary when entering done step
        if (step == 6) buildSummary()

        // Cancel auto-discover when leaving server step
        if (step != 3) discoverCancelled.set(true)

        // Scroll to top
        try { findViewById<ScrollView>(R.id.setupScrollView).scrollTo(0, 0) } catch (_: Exception) {}
    }

    private fun updateProgressDots() {
        progressDots.removeAllViews()
        // Show 5 dots for steps 1-5; step 0 (language) and step 6 (done) have no dots
        if (currentStep == 0 || currentStep == 6) return
        val active  = currentStep  // 1..5
        val density = resources.displayMetrics.density
        for (i in 1..5) {
            val dot = View(this)
            val sizeDp = if (i == active) 10 else 7
            val px = (sizeDp * density).toInt()
            val lp = LinearLayout.LayoutParams(px, px)
            lp.marginStart = (5 * density).toInt()
            lp.marginEnd   = (5 * density).toInt()
            dot.layoutParams = lp
            val bg = android.graphics.drawable.GradientDrawable()
            bg.shape = android.graphics.drawable.GradientDrawable.OVAL
            bg.setColor(when {
                i < active  -> 0xFF34d399.toInt()  // completed
                i == active -> 0xFF7c3aed.toInt()  // current
                else        -> 0xFF334155.toInt()  // future
            })
            dot.background = bg
            progressDots.addView(dot)
        }
    }

    // ── Exit ──────────────────────────────────────────────────────────────

    private fun confirmExit() {
        AlertDialog.Builder(this)
            .setTitle(getString(R.string.setup_exit_title))
            .setMessage(getString(R.string.setup_exit_message))
            .setPositiveButton(getString(R.string.setup_exit_confirm)) { _, _ ->
                bleManager?.stopScan()
                discoverCancelled.set(true)
                finishAffinity()
            }
            .setNegativeButton(getString(R.string.setup_exit_cancel), null)
            .show()
    }

    // ── Permissions ───────────────────────────────────────────────────────

    private fun allPermissionsGranted(): Boolean {
        val cam = ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED
        val mic = ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED
        return cam && mic
    }

    private fun requestPermissions() {
        val needed = mutableListOf<String>()
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED)
            needed.add(Manifest.permission.CAMERA)
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED)
            needed.add(Manifest.permission.RECORD_AUDIO)
        if (Build.VERSION.SDK_INT >= 33) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_MEDIA_IMAGES) != PackageManager.PERMISSION_GRANTED)
                needed.add(Manifest.permission.READ_MEDIA_IMAGES)
        } else {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED)
                needed.add(Manifest.permission.READ_EXTERNAL_STORAGE)
        }
        // BLE permissions (needed for scale integration)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_SCAN) != PackageManager.PERMISSION_GRANTED)
                needed.add(Manifest.permission.BLUETOOTH_SCAN)
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED)
                needed.add(Manifest.permission.BLUETOOTH_CONNECT)
        } else {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED)
                needed.add(Manifest.permission.ACCESS_FINE_LOCATION)
        }
        if (needed.isEmpty()) {
            onPermissionsGranted()
        } else {
            ActivityCompat.requestPermissions(this, needed.toTypedArray(), PERMISSION_REQUEST_CODE)
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == PERMISSION_REQUEST_CODE || requestCode == BLE_PERMISSION_REQUEST) {
            onPermissionsGranted()
        }
    }

    private fun onPermissionsGranted() {
        permsGrantedCard.visibility = View.GONE
        btnGrantPerms.text = getString(R.string.setup_perms_granted_next)
        btnGrantPerms.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF34d399.toInt())
        btnGrantPerms.setTextColor(0xFF0f172a.toInt())
        btnGrantPerms.setOnClickListener { showStep(3) }
    }

    // ── Connection Test ───────────────────────────────────────────────────

    private fun testConnection() {
        val url = urlEdit.text.toString().trim()
        if (url.isEmpty()) { showUrlStatus(getString(R.string.setup_enter_url), false); return }
        showUrlStatus(getString(R.string.setup_testing), null)

        Thread {
            val base = url.trimEnd('/')
            // Try both API path variants
            val candidates = listOf(
                "$base/api/index.php?action=get_settings",
                "$base/api/?action=get_settings"
            )
            var found = false
            for (apiUrl in candidates) {
                val conn = openConn(apiUrl) ?: continue
                try {
                    val code = conn.responseCode
                    if (code !in 200..399) { conn.disconnect(); continue }
                    val body = conn.inputStream.bufferedReader().readText()
                    conn.disconnect()
                    if (body.contains("gemini_key_set") || body.contains("\"success\"")) {
                        found = true; break
                    }
                } catch (_: Exception) { try { conn.disconnect() } catch (_: Exception) {} }
            }
            // If API not found, try plain base URL to distinguish unreachable vs wrong path
            if (!found) {
                var baseReachable = false
                try {
                    val conn = openConn(base) ?: openConn("$base/")
                    val code = conn?.responseCode ?: -1
                    conn?.disconnect()
                    baseReachable = code in 200..499
                } catch (_: Exception) {}
                runOnUiThread {
                    if (baseReachable) {
                        showUrlStatus("⚠ ${getString(R.string.setup_api_not_found)}", false)
                    } else {
                        showUrlStatus("✗ ${getString(R.string.setup_unreachable)}", false)
                    }
                }
            } else {
                runOnUiThread { showUrlStatus("✅ ${getString(R.string.setup_server_found)}", true) }
            }
        }.start()
    }

    private fun showUrlStatus(text: String, success: Boolean?) {
        urlStatus.visibility = View.VISIBLE
        urlStatus.text = text
        urlStatus.setTextColor(when (success) {
            true  -> 0xFF34d399.toInt()
            false -> 0xFFf87171.toInt()
            null  -> 0xFF94a3b8.toInt()
        })
    }

    private fun openConn(urlStr: String): HttpURLConnection? {
        return try {
            val conn = URL(urlStr).openConnection()
            if (conn is HttpsURLConnection) {
                val trustAll = arrayOf<TrustManager>(object : X509TrustManager {
                    override fun checkClientTrusted(c: Array<java.security.cert.X509Certificate>?, t: String?) {}
                    override fun checkServerTrusted(c: Array<java.security.cert.X509Certificate>?, t: String?) {}
                    override fun getAcceptedIssuers(): Array<java.security.cert.X509Certificate> = arrayOf()
                })
                val sc = SSLContext.getInstance("TLS")
                sc.init(null, trustAll, java.security.SecureRandom())
                conn.sslSocketFactory = sc.socketFactory
                conn.hostnameVerifier = javax.net.ssl.HostnameVerifier { _, _ -> true }
            }
            (conn as HttpURLConnection).apply {
                requestMethod = "GET"
                connectTimeout = 3000
                readTimeout    = 3000
            }
        } catch (_: Exception) { null }
    }

    // ── Auto-Discover ─────────────────────────────────────────────────────

    private fun autoDiscover() {
        discoverCancelled.set(false)
        btnDiscover.isEnabled = false
        btnDiscover.text = getString(R.string.setup_discovering)
        discoverStatus.visibility = View.VISIBLE
        discoverStatus.text = getString(R.string.setup_discovering_detail)
        discoverStatus.setTextColor(0xFF94a3b8.toInt())

        Thread {
            // ── 1. Detect subnets — prefer Wi-Fi/Ethernet, skip VPN/cellular ──────
            // Prefixes to skip: VPN tunnels, cellular data, hotspot virtuals, etc.
            val skipPrefixes = listOf("tun", "ppp", "rmnet", "pdp", "ccmni",
                "dummy", "sit", "gre", "v4-", "v6-", "p2p", "ham", "nordlynx")
            val wifiSubnets  = mutableListOf<String>()  // wlan/eth — highest priority
            val otherSubnets = mutableListOf<String>()  // everything else that is real
            try {
                val interfaces = NetworkInterface.getNetworkInterfaces()
                while (interfaces != null && interfaces.hasMoreElements()) {
                    val intf = interfaces.nextElement()
                    if (!intf.isUp || intf.isLoopback || intf.isVirtual) continue
                    val name = intf.name.lowercase()
                    if (skipPrefixes.any { name.startsWith(it) }) continue
                    for (addr in intf.interfaceAddresses) {
                        val ip = addr.address
                        if (ip is java.net.Inet4Address && !ip.isLoopbackAddress) {
                            val parts = ip.hostAddress?.split(".") ?: continue
                            if (parts.size == 4) {
                                val subnet = "${parts[0]}.${parts[1]}.${parts[2]}"
                                if (name.startsWith("wlan") || name.startsWith("eth")) {
                                    if (!wifiSubnets.contains(subnet)) wifiSubnets += subnet
                                } else {
                                    if (!otherSubnets.contains(subnet)) otherSubnets += subnet
                                }
                            }
                        }
                    }
                }
            } catch (_: Exception) {}
            // WiFi first, then others, then hardcoded fallbacks (deduped)
            val subnets = (wifiSubnets + otherSubnets).toMutableList()
            for (s in listOf("192.168.1", "192.168.0", "192.168.2", "10.0.0", "10.0.1")) {
                if (!subnets.contains(s)) subnets += s
            }

            // Show detected subnets in status
            val detectedLabel = if (wifiSubnets.isNotEmpty())
                wifiSubnets.joinToString(", ") { "$it.x" }
            else getString(R.string.setup_discovering_detail)
            runOnUiThread { discoverStatus.text = "📡  $detectedLabel" }

            val ports = listOf(443, 80, 8080, 8443)
            val paths = listOf(
                "/api/index.php?action=get_settings",
                "/dispensa/api/index.php?action=get_settings",
                "/evershelf/api/index.php?action=get_settings",
            )

            // Build full task list: subnet-first ordering ensures local subnet is scanned first
            val allTargets = mutableListOf<Pair<String, Int>>()
            for (subnet in subnets.distinct()) {
                for (i in 1..254) {
                    for (port in ports) {
                        allTargets += "$subnet.$i" to port
                    }
                }
            }

            val executor = Executors.newFixedThreadPool(60)
            val cs = ExecutorCompletionService<String?>(executor)
            val found = AtomicBoolean(false)
            val scanned = AtomicInteger(0)
            val total = allTargets.size
            val lastUiMs = AtomicLong(0L)

            // ── 2. Submit all tasks ─────────────────────────────────────────────
            for ((ip, port) in allTargets) {
                cs.submit {
                    if (discoverCancelled.get() || found.get()) return@submit null

                    val n = scanned.incrementAndGet()
                    // Update status ~8 fps (every 120 ms) without hammering the UI thread
                    val now = System.currentTimeMillis()
                    if (now - lastUiMs.get() > 120) {
                        lastUiMs.set(now)
                        runOnUiThread {
                            discoverStatus.text = "🔍  $ip:$port  ($n / $total)"
                        }
                    }

                    // TCP pre-check (600 ms) — skips unreachable hosts instantly
                    val reachable = try {
                        Socket().use { s -> s.connect(InetSocketAddress(ip, port), 600); true }
                    } catch (_: Exception) { false }

                    if (!reachable || discoverCancelled.get() || found.get()) return@submit null

                    // Full HTTP probe on reachable host
                    val scheme = if (port == 443 || port == 8443) "https" else "http"
                    for (path in paths) {
                        if (discoverCancelled.get() || found.get()) break
                        val urlStr = "$scheme://$ip:$port$path"
                        try {
                            val conn = openConn(urlStr) ?: continue
                            val code = conn.responseCode
                            if (code in 200..399) {
                                val body = conn.inputStream.bufferedReader().readText()
                                conn.disconnect()
                                if (body.contains("gemini_key_set") || body.contains("\"success\"")) {
                                    return@submit urlStr.substringBefore("/api/") + "/"
                                }
                            } else conn.disconnect()
                        } catch (_: Exception) {}
                    }
                    null
                }
            }

            // ── 3. Collect results as they complete (not in submission order) ────
            var result: String? = null
            var collected = 0
            while (collected < total && !discoverCancelled.get()) {
                val future = cs.poll(3, TimeUnit.SECONDS) ?: break
                collected++
                val r = try { future.get() } catch (_: Exception) { null }
                if (r != null && found.compareAndSet(false, true)) {
                    result = r
                    break
                }
            }
            executor.shutdownNow()

            val finalResult = result
            runOnUiThread {
                when {
                    finalResult != null -> {
                        urlEdit.setText(finalResult)
                        discoverStatus.text = "✅ ${getString(R.string.setup_server_found)}: $finalResult"
                        discoverStatus.setTextColor(0xFF34d399.toInt())
                        showUrlStatus("✅ ${getString(R.string.setup_server_found)}", true)
                    }
                    !discoverCancelled.get() -> {
                        discoverStatus.text = getString(R.string.setup_discover_not_found)
                        discoverStatus.setTextColor(0xFFf87171.toInt())
                    }
                }
                btnDiscover.isEnabled = true
                btnDiscover.text = getString(R.string.setup_discover_btn)
            }
        }.start()
    }

    // ── BLE Scale ─────────────────────────────────────────────────────────

    private fun startBleScan() {
        val mgr = bleManager ?: return
        if (!mgr.hasRequiredPermissions()) {
            val needed = mutableListOf<String>()
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                if (ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_SCAN) != PackageManager.PERMISSION_GRANTED)
                    needed.add(Manifest.permission.BLUETOOTH_SCAN)
                if (ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED)
                    needed.add(Manifest.permission.BLUETOOTH_CONNECT)
            } else {
                if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED)
                    needed.add(Manifest.permission.ACCESS_FINE_LOCATION)
            }
            if (needed.isNotEmpty()) {
                ActivityCompat.requestPermissions(this, needed.toTypedArray(), BLE_PERMISSION_REQUEST)
                return
            }
        }
        discoveredDevices.clear()
        deviceAdapter?.notifyDataSetChanged()
        tvScanStatus.text = "🔍 Scansione in corso…"
        tvScanStatus.setTextColor(0xFF94a3b8.toInt())
        btnScanBle.isEnabled = false
        mgr.startScan()
    }

    private fun onDeviceSelected(info: BleDeviceInfo) {
        bleManager?.stopScan()
        selectedDevice = info
        bleManager?.saveDevice(info.device.address, info.name)
        tvSelectedScale.text       = "✅ ${info.name}"
        tvSelectedScale.visibility = View.VISIBLE
        btnScanBle.isEnabled = true
        btnScanBle.text = "🔄  Scansiona di nuovo"
        // Start connection test
        startScaleTest(info)
    }

    private fun startScaleTest(info: BleDeviceInfo) {
        isInTestMode = true
        testHasWeight = false
        // Show test card, hide scan card
        bleSetupCard.visibility  = View.GONE
        scaleTestCard.visibility = View.VISIBLE
        testWeightBox.visibility = View.GONE
        step3NextButtons.visibility = View.GONE
        tvTestStatus.text = "🔗 Connessione a ${info.name}…"
        tvTestStatus.setTextColor(0xFF94a3b8.toInt())
        tvTestWeight.text = "— g"
        // Disable confirm/retry until we have data
        findViewById<MaterialButton>(R.id.btnTestConfirm).isEnabled = false
        findViewById<MaterialButton>(R.id.btnTestRetry).isEnabled   = false
        bleManager?.connect(info.device)
    }

    private fun makeBleListener() = object : BleScaleListener {
        override fun onDeviceFound(info: BleDeviceInfo) {
            // Only show devices that look like scales (positive score)
            if (info.scaleScore <= 0) return
            val existing = discoveredDevices.indexOfFirst { it.device.address == info.device.address }
            if (existing >= 0) {
                discoveredDevices[existing] = info
                deviceAdapter?.notifyItemChanged(existing)
            } else {
                discoveredDevices.add(info)
                deviceAdapter?.notifyItemInserted(discoveredDevices.size - 1)
            }
        }
        override fun onConnecting(device: BluetoothDevice) {
            if (!isInTestMode) return
            tvTestStatus.text = "🔗 Connessione in corso…"
            tvTestStatus.setTextColor(0xFF94a3b8.toInt())
        }
        override fun onConnected(deviceName: String) {
            if (!isInTestMode) return
            tvTestStatus.text = "⚖️ Connesso! Posiziona un oggetto sulla bilancia…"
            tvTestStatus.setTextColor(0xFF34d399.toInt())
            testWeightBox.visibility = View.VISIBLE
            findViewById<MaterialButton>(R.id.btnTestRetry).isEnabled = true
        }
        override fun onDisconnected() {
            if (!isInTestMode) return
            tvTestStatus.text = "⚠️ Connessione persa. Riprova."
            tvTestStatus.setTextColor(0xFFfbbf24.toInt())
            testWeightBox.visibility = View.GONE
            testHasWeight = false
            findViewById<MaterialButton>(R.id.btnTestConfirm).isEnabled = false
        }
        override fun onWeightReceived(reading: WeightReading) {
            if (!isInTestMode) return
            testHasWeight = true
            val display = if (reading.unit == "kg")
                "%.3f kg".format(reading.value)
            else
                "%g ${reading.unit}".format(reading.value)
            tvTestWeight.text = display
            testWeightBox.visibility = View.VISIBLE
            tvTestStatus.text = "Peso ricevuto — coincide con quello sulla bilancia?"
            tvTestStatus.setTextColor(0xFF94a3b8.toInt())
            findViewById<MaterialButton>(R.id.btnTestConfirm).isEnabled = true
            findViewById<MaterialButton>(R.id.btnTestRetry).isEnabled   = true
        }
        override fun onBatteryReceived(level: Int) {}
        override fun onError(message: String) {
            if (isInTestMode) {
                tvTestStatus.text = "⚠️ $message"
                tvTestStatus.setTextColor(0xFFf87171.toInt())
                testWeightBox.visibility = View.GONE
                testHasWeight = false
                findViewById<MaterialButton>(R.id.btnTestRetry).isEnabled = true
                return
            }
            tvScanStatus.text = "⚠️ $message"
            tvScanStatus.setTextColor(0xFFf87171.toInt())
            btnScanBle.isEnabled = true
        }
        override fun onScanStopped() {
            btnScanBle.isEnabled = true
            if (discoveredDevices.isEmpty()) {
                tvScanStatus.text = "Nessuna bilancia trovata. Assicurati che sia accesa e vicina, poi riprova."
                tvScanStatus.setTextColor(0xFFfbbf24.toInt())
            } else {
                tvScanStatus.text = "Seleziona la tua bilancia dall'elenco."
                tvScanStatus.setTextColor(0xFF94a3b8.toInt())
            }
        }
        override fun onDebugEvent(message: String) {}
    }

    // ── Device list adapter ────────────────────────────────────────────────

    private inner class DeviceAdapter(
        private val onSelect: (BleDeviceInfo) -> Unit,
    ) : RecyclerView.Adapter<DeviceAdapter.VH>() {

        inner class VH(view: View) : RecyclerView.ViewHolder(view) {
            val tvName:   TextView = view.findViewById(android.R.id.text1)
            val tvDetail: TextView = view.findViewById(android.R.id.text2)
        }

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
            val v = LayoutInflater.from(parent.context)
                .inflate(android.R.layout.simple_list_item_2, parent, false)
            v.setBackgroundColor(0x1A7c3aed)
            val density = parent.context.resources.displayMetrics.density
            val lp = v.layoutParams as? RecyclerView.LayoutParams
            lp?.bottomMargin = (6 * density).toInt()
            v.layoutParams = lp
            val pad = (12 * density).toInt()
            val padV = (10 * density).toInt()
            v.setPadding(pad, padV, pad, padV)
            return VH(v)
        }

        override fun onBindViewHolder(holder: VH, position: Int) {
            val info  = discoveredDevices[position]
            holder.tvName.text = info.name
            holder.tvName.setTextColor(0xFFf1f5f9.toInt())
            holder.tvName.textSize = 15f
            val score = if (info.scaleScore >= 10) "⭐ probabile bilancia  •  " else ""
            holder.tvDetail.text = "$score${info.proximity}  •  ${info.rssi} dBm"
            holder.tvDetail.setTextColor(0xFF94a3b8.toInt())
            holder.tvDetail.textSize = 12f
            holder.itemView.setOnClickListener { onSelect(info) }
        }

        override fun getItemCount() = discoveredDevices.size
    }

    // ── Summary / Finish ─────────────────────────────────────────────────

    private fun buildSummary() {
        val url        = prefs.getString(KEY_URL, "") ?: ""
        val hasScale   = prefs.getBoolean(KEY_HAS_SCALE, false)
        val screensOn  = setupSwitchScreensaver.isChecked
        val pricesOn   = setupSwitchPrices.isChecked
        val mealPlanOn = setupSwitchMealPlan.isChecked
        val zeroWasteOn = setupSwitchZeroWaste.isChecked
        val scaleName  = bleManager?.getSavedDeviceName()
        val scaleOk    = hasScale && scaleName != null
        val lang       = prefs.getString(KEY_LANGUAGE, "it") ?: "it"
        val langLabel  = when (lang) { "en" -> "English 🇬🇧"; "de" -> "Deutsch 🇩🇪"; else -> "Italiano 🇮🇹" }
        val sb = StringBuilder()
        sb.appendLine("🌐 ${getString(R.string.summary_lang)}: $langLabel")
        if (url.isNotEmpty()) sb.appendLine("🖥️ Server: $url")
        sb.appendLine(when {
            scaleOk  -> "✅ Bilancia: $scaleName"
            hasScale -> "⚠️ Bilancia: da configurare"
            else     -> "⏭ ${getString(R.string.summary_scale_skip)}"
        })
        sb.appendLine(if (screensOn)   getString(R.string.summary_screensaver_on)  else getString(R.string.summary_screensaver_off))
        if (pricesOn)    sb.appendLine(getString(R.string.summary_prices_on))
        if (mealPlanOn)  sb.appendLine(getString(R.string.summary_mealplan_on))
        if (zeroWasteOn) sb.appendLine(getString(R.string.summary_zerowaste_on))
        summaryText.text = sb.toString().trimEnd()
    }

    private fun finishSetup() {
        prefs.edit().putBoolean(KEY_SETUP_COMPLETE, true).apply()
        val baseUrl = (prefs.getString(KEY_URL, "") ?: "").trimEnd('/')
        if (baseUrl.isNotEmpty()) {
            val hasScale      = prefs.getBoolean(KEY_HAS_SCALE, false) && (bleManager?.getSavedDeviceAddress() != null)
            val screensaver   = prefs.getBoolean(KEY_SCREENSAVER,   false)
            val priceEnabled  = prefs.getBoolean(KEY_PRICE_ENABLED,  false)
            val mealPlan      = prefs.getBoolean(KEY_MEAL_PLAN,      false)
            val zeroWaste     = prefs.getBoolean(KEY_ZEROWASTE_TIPS, false)
            Thread {
                try {
                    val url  = "$baseUrl/api/index.php?action=save_settings"
                    val body = buildString {
                        append("{\"screensaver_enabled\":$screensaver")
                        append(",\"price_enabled\":$priceEnabled")
                        append(",\"meal_plan_enabled\":$mealPlan")
                        append(",\"zerowaste_tips_enabled\":$zeroWaste")
                        if (hasScale) {
                            val lanIp = getDeviceLanIp() ?: "127.0.0.1"
                            append(",\"scale_enabled\":true,\"scale_gateway_url\":\"ws://$lanIp:8765\"")
                        }
                        append("}")
                    }
                    val conn = (java.net.URL(url).openConnection() as java.net.HttpURLConnection).apply {
                        requestMethod = "POST"
                        setRequestProperty("Content-Type", "application/json")
                        connectTimeout = 5000
                        readTimeout    = 5000
                        doOutput = true
                    }
                    conn.outputStream.use { it.write(body.toByteArray()) }
                    conn.inputStream.close()
                    conn.disconnect()
                } catch (_: Exception) {}
            }.start()
        }
        setResult(RESULT_OK)
        finish()
    }

    /**
     * Returns the device's best LAN IPv4 address (Wi-Fi/Ethernet preferred).
     * Skips loopback, VPN tunnels, and cellular interfaces.
     */
    private fun getDeviceLanIp(): String? {
        val skipPrefixes = listOf("tun", "ppp", "rmnet", "pdp", "v4-", "v6-", "ccmni", "sit", "gre")
        var fallback: String? = null
        try {
            val ifaces = NetworkInterface.getNetworkInterfaces() ?: return null
            while (ifaces.hasMoreElements()) {
                val intf = ifaces.nextElement()
                if (!intf.isUp || intf.isLoopback) continue
                val name = intf.name.lowercase()
                if (skipPrefixes.any { name.startsWith(it) }) continue
                for (addr in intf.interfaceAddresses) {
                    val ip = addr.address
                    if (ip is java.net.Inet4Address && !ip.isLoopbackAddress) {
                        val ipStr = ip.hostAddress ?: continue
                        // Wi-Fi/Ethernet first
                        if (name.startsWith("wlan") || name.startsWith("eth")) return ipStr
                        if (fallback == null) fallback = ipStr
                    }
                }
            }
        } catch (_: Exception) {}
        return fallback
    }
}
