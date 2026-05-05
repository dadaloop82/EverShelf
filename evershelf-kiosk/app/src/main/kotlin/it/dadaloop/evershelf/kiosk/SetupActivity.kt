package it.dadaloop.evershelf.kiosk

import android.Manifest
import android.app.AlertDialog
import android.app.DownloadManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.SharedPreferences
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.View
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.google.android.material.button.MaterialButton
import com.google.android.material.switchmaterial.SwitchMaterial
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

    // Scale step
    private lateinit var scaleQuestionCard:  LinearLayout
    private lateinit var gatewayInfoCard:    LinearLayout
    private lateinit var gatewayInstallCard: LinearLayout
    private lateinit var gatewayStatusIcon:  TextView
    private lateinit var gatewayStatusText:  TextView
    private lateinit var gatewayStatusDetail: TextView
    private lateinit var btnInstallGateway:   MaterialButton
    private lateinit var btnConfigureGateway: MaterialButton
    private lateinit var gatewayProgressBar: ProgressBar
    private lateinit var gatewayProgressText: TextView
    private lateinit var step3NextButtons:   LinearLayout

    // Screensaver step
    private lateinit var setupSwitchScreensaver: SwitchMaterial

    // Done step
    private lateinit var summaryText: TextView

    // Permissions step
    private lateinit var permsGrantedCard: LinearLayout
    private lateinit var btnGrantPerms:    MaterialButton

    // APK install state (for gateway)
    private var pendingApkDownloadUrl = ""
    private var pendingInstallFile: java.io.File? = null
    private var pendingInstallPkg  = ""
    private val pollHandler = Handler(Looper.getMainLooper())
    private var activeDownloadId: Long = -1

    // Auto-discover cancellation flag
    private val discoverCancelled = AtomicBoolean(false)

    companion object {
        private const val PREFS_NAME         = "evershelf_kiosk"
        private const val KEY_URL            = "evershelf_url"
        private const val KEY_SETUP_COMPLETE = "setup_complete"
        private const val KEY_HAS_SCALE      = "has_scale"
        private const val KEY_LANGUAGE       = "kiosk_language"
        private const val KEY_SCREENSAVER    = "screensaver_enabled"
        private const val GATEWAY_PACKAGE    = "it.dadaloop.evershelf.scalegate"
        private const val GATEWAY_DOWNLOAD_URL =
            "https://github.com/dadaloop82/EverShelf/releases/latest/download/evershelf-scale-gateway.apk"
        private const val INSTALL_PERM_REQUEST    = 2001
        private const val INSTALL_CONFIRM_REQUEST = 2002
        private const val UNINSTALL_REQUEST       = 2003
        private const val PERMISSION_REQUEST_CODE = 2004
        private const val INSTALL_FALLBACK_REQUEST = 2005

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
        val langAlreadySet = prefs.getString(KEY_LANGUAGE, null) != null
        showStep(when {
            savedStep >= 0    -> savedStep
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
        pollHandler.removeCallbacksAndMessages(null)
        discoverCancelled.set(true)
        super.onDestroy()
    }

    override fun onResume() {
        super.onResume()
        // When returning from the gateway app (after pressing "Configura"), refresh status
        if (currentStep == 4 && gatewayInstallCard.visibility == View.VISIBLE) {
            checkGatewayStatus()
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
        scaleQuestionCard   = findViewById(R.id.scaleQuestionCard)
        gatewayInfoCard     = findViewById(R.id.gatewayInfoCard)
        gatewayInstallCard  = findViewById(R.id.gatewayInstallCard)
        gatewayStatusIcon   = findViewById(R.id.gatewayStatusIcon)
        gatewayStatusText   = findViewById(R.id.gatewayStatusText)
        gatewayStatusDetail  = findViewById(R.id.gatewayStatusDetail)
        btnInstallGateway    = findViewById(R.id.btnInstallGateway)
        btnConfigureGateway  = findViewById(R.id.btnConfigureGateway)
        gatewayProgressBar   = findViewById(R.id.gatewayProgressBar)
        gatewayProgressText = findViewById(R.id.gatewayProgressText)
        step3NextButtons    = findViewById(R.id.step3NextButtons)

        // Screensaver step
        setupSwitchScreensaver = findViewById(R.id.setupSwitchScreensaver)
        // Pre-fill saved screensaver pref
        setupSwitchScreensaver.isChecked = prefs.getBoolean(KEY_SCREENSAVER, false)

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
        findViewById<MaterialButton>(R.id.btnScaleYes).setOnClickListener {
            scaleQuestionCard.visibility  = View.GONE
            gatewayInfoCard.visibility    = View.VISIBLE
            gatewayInstallCard.visibility = View.VISIBLE
            step3NextButtons.visibility   = View.VISIBLE
            checkGatewayStatus()
        }
        findViewById<MaterialButton>(R.id.btnScaleNo).setOnClickListener {
            prefs.edit().putBoolean(KEY_HAS_SCALE, false).apply()
            showStep(5)
        }
        btnInstallGateway.setOnClickListener {
            pendingApkDownloadUrl = GATEWAY_DOWNLOAD_URL
            triggerApkDownload(GATEWAY_DOWNLOAD_URL)
        }
        btnConfigureGateway.setOnClickListener {
            val intent = packageManager.getLaunchIntentForPackage(GATEWAY_PACKAGE)
            if (intent != null) {
                startActivity(intent)
            } else {
                Toast.makeText(this, "Gateway non trovato", Toast.LENGTH_SHORT).show()
            }
        }
        findViewById<MaterialButton>(R.id.btnScaleBack).setOnClickListener { showStep(3) }
        findViewById<MaterialButton>(R.id.btnScaleNext).setOnClickListener {
            prefs.edit().putBoolean(KEY_HAS_SCALE, true).apply()
            showStep(5)
        }

        // ── Screensaver ───────────────────────────────────────────────────
        findViewById<MaterialButton>(R.id.btnScreensaverBack).setOnClickListener { showStep(4) }
        findViewById<MaterialButton>(R.id.btnScreensaverNext).setOnClickListener {
            prefs.edit().putBoolean(KEY_SCREENSAVER, setupSwitchScreensaver.isChecked).apply()
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
            val scaleAlreadyConfiguredYes = prefs.contains(KEY_HAS_SCALE) && prefs.getBoolean(KEY_HAS_SCALE, false)
            if (scaleAlreadyConfiguredYes) {
                // User already confirmed they have a scale — skip the question
                scaleQuestionCard.visibility  = View.GONE
                gatewayInfoCard.visibility    = View.VISIBLE
                gatewayInstallCard.visibility = View.VISIBLE
                step3NextButtons.visibility   = View.VISIBLE
                checkGatewayStatus()
            } else {
                scaleQuestionCard.visibility  = View.VISIBLE
                gatewayInfoCard.visibility    = View.GONE
                gatewayInstallCard.visibility = View.GONE
                step3NextButtons.visibility   = View.GONE
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
                pollHandler.removeCallbacksAndMessages(null)
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
        if (needed.isEmpty()) {
            onPermissionsGranted()
        } else {
            ActivityCompat.requestPermissions(this, needed.toTypedArray(), PERMISSION_REQUEST_CODE)
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == PERMISSION_REQUEST_CODE) {
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

    // ── Gateway ────────────────────────────────────────────────────────────

    private fun isGatewayInstalled() = try {
        packageManager.getPackageInfo(GATEWAY_PACKAGE, 0); true
    } catch (_: PackageManager.NameNotFoundException) { false }

    private fun checkGatewayStatus() {
        if (isGatewayInstalled()) {
            gatewayStatusIcon.text = "✅"
            gatewayStatusText.text = getString(R.string.wizard_gateway_installed)
            gatewayStatusDetail.text = "⏳ Verifica connessione in corso..."
            gatewayStatusDetail.setTextColor(0xFF94a3b8.toInt())
            btnInstallGateway.visibility    = View.GONE
            btnConfigureGateway.visibility  = View.VISIBLE
            gatewayProgressBar.visibility   = View.GONE
            gatewayProgressText.visibility  = View.GONE
            // Probe WebSocket port to tell user if gateway is actually running
            Thread {
                val running = try {
                    java.net.Socket().use { s ->
                        s.connect(java.net.InetSocketAddress("127.0.0.1", 8765), 1200)
                        true
                    }
                } catch (_: Exception) { false }
                runOnUiThread {
                    if (running) {
                        gatewayStatusDetail.text = "✅ Gateway attivo su ws://127.0.0.1:8765"
                        gatewayStatusDetail.setTextColor(0xFF34d399.toInt())
                        btnConfigureGateway.text = "⚙️  Riapri Gateway per configurarlo"
                    } else {
                        gatewayStatusDetail.text =
                            "⚠️ Gateway installato ma non ancora avviato.\n" +
                            "Premi il pulsante qui sotto per aprirlo e configurarlo, poi torna a questa schermata."
                        gatewayStatusDetail.setTextColor(0xFFfbbf24.toInt())
                        btnConfigureGateway.text = "▶️  Apri e configura Gateway"
                    }
                }
            }.start()
        } else {
            gatewayStatusIcon.text = "📲"
            gatewayStatusText.text = getString(R.string.wizard_gateway_not_installed)
            gatewayStatusDetail.text = getString(R.string.wizard_gateway_not_installed_detail)
            gatewayStatusDetail.setTextColor(0xFFfbbf24.toInt())
            btnInstallGateway.visibility   = View.VISIBLE
            btnConfigureGateway.visibility = View.GONE
        }
    }

    private fun setGatewayUI(icon: String, text: String, detail: String, color: Int,
                              btnEnabled: Boolean = true, progress: Int = -2) {
        runOnUiThread {
            gatewayStatusIcon.text   = icon
            gatewayStatusText.text   = text
            gatewayStatusDetail.text = detail
            gatewayStatusDetail.setTextColor(color)
            btnInstallGateway.isEnabled = btnEnabled
            when {
                progress == -2 -> {
                    gatewayProgressBar.visibility = View.GONE
                    gatewayProgressText.visibility = View.GONE
                }
                progress == -1 -> {
                    gatewayProgressBar.isIndeterminate = true
                    gatewayProgressBar.visibility = View.VISIBLE
                    gatewayProgressText.visibility = View.GONE
                }
                else -> {
                    gatewayProgressBar.isIndeterminate = false
                    gatewayProgressBar.progress  = progress
                    gatewayProgressBar.visibility = View.VISIBLE
                    gatewayProgressText.visibility = View.VISIBLE
                }
            }
        }
    }

    private fun startProgressPoll(downloadId: Long) {
        activeDownloadId = downloadId
        pollHandler.removeCallbacksAndMessages(null)
        fun tick() {
            if (activeDownloadId != downloadId) return
            val dm = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
            val c  = dm.query(DownloadManager.Query().setFilterById(downloadId))
            if (!c.moveToFirst()) { c.close(); return }
            val status = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS))
            if (status == DownloadManager.STATUS_RUNNING || status == DownloadManager.STATUS_PENDING) {
                val dl  = c.getLong(c.getColumnIndexOrThrow(DownloadManager.COLUMN_BYTES_DOWNLOADED_SO_FAR))
                val tot = c.getLong(c.getColumnIndexOrThrow(DownloadManager.COLUMN_TOTAL_SIZE_BYTES))
                c.close()
                val pct = if (tot > 0) (dl * 100 / tot).toInt() else 0
                val txt = if (tot > 0) "%.1f / %.1f MB".format(dl / 1_048_576f, tot / 1_048_576f) else ""
                setGatewayUI(
                    "⏳",
                    getString(R.string.install_downloading) + if (tot > 0) " ($pct%)" else "",
                    txt, 0xFF94a3b8.toInt(), btnEnabled = false, progress = pct
                )
                runOnUiThread { gatewayProgressText.text = txt }
                pollHandler.postDelayed({ tick() }, 500)
            } else {
                c.close()
            }
        }
        pollHandler.post { tick() }
    }

    private fun triggerApkDownload(apkUrl: String) {
        pendingApkDownloadUrl = apkUrl
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !packageManager.canRequestPackageInstalls()) {
            @Suppress("DEPRECATION")
            startActivityForResult(
                Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:$packageName")),
                INSTALL_PERM_REQUEST
            )
            return
        }
        setGatewayUI("⏳", getString(R.string.install_downloading), "", 0xFF94a3b8.toInt(), btnEnabled = false, progress = -1)
        val destDir  = getExternalFilesDir(null) ?: filesDir
        val destFile = java.io.File(destDir, "evershelf-gateway-setup.apk")
        val dm  = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
        val req = DownloadManager.Request(Uri.parse(apkUrl)).apply {
            setTitle("EverShelf Scale Gateway")
            setDescription(getString(R.string.install_downloading))
            setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            setDestinationUri(Uri.fromFile(destFile))
            setMimeType("application/vnd.android.package-archive")
        }
        val downloadId = dm.enqueue(req)
        startProgressPoll(downloadId)

        val receiver = object : BroadcastReceiver() {
            override fun onReceive(ctx: Context?, intent: Intent?) {
                val id = intent?.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1)
                if (id != downloadId) return
                unregisterReceiver(this)
                val q  = DownloadManager.Query().setFilterById(downloadId)
                val c  = (getSystemService(DOWNLOAD_SERVICE) as DownloadManager).query(q)
                var ok = false
                if (c.moveToFirst()) {
                    ok = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS)) ==
                         DownloadManager.STATUS_SUCCESSFUL
                }
                c.close()
                pollHandler.removeCallbacksAndMessages(null)
                activeDownloadId = -1
                if (ok) {
                    setGatewayUI("⏳", getString(R.string.install_installing), "", 0xFF94a3b8.toInt(), btnEnabled = false, progress = -1)
                    installApk(destFile)
                } else {
                    setGatewayUI("❌", getString(R.string.install_error_download), getString(R.string.install_error_download_detail), 0xFFf87171.toInt())
                }
            }
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(receiver, IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE), RECEIVER_EXPORTED)
        } else {
            @Suppress("UnspecifiedRegisterReceiverFlag")
            registerReceiver(receiver, IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE))
        }
    }

    private fun installApk(file: java.io.File) {
        if (!file.exists() || file.length() == 0L) {
            setGatewayUI("❌", getString(R.string.install_error_download), "File APK non trovato sul dispositivo.", 0xFFf87171.toInt())
            return
        }
        // Validate APK magic bytes (ZIP header)
        val magic = try { file.inputStream().use { s -> val b = ByteArray(4); s.read(b); b } } catch (_: Exception) { null }
        if (magic == null || magic[0] != 0x50.toByte() || magic[1] != 0x4B.toByte()) {
            setGatewayUI("❌", getString(R.string.install_error_download), "Il file scaricato non è un APK valido.", 0xFFf87171.toInt())
            file.delete()
            return
        }
        // Double-check install permission at runtime (may have been revoked or not granted yet)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !packageManager.canRequestPackageInstalls()) {
            AlertDialog.Builder(this)
                .setTitle("⚠️ Permesso mancante")
                .setMessage("Per installare il Gateway è necessario abilitare \"Installa app sconosciute\" per questa app.\n\nTocca OK per aprire le impostazioni.")
                .setPositiveButton("OK") { _, _ ->
                    pendingInstallFile = file
                    @Suppress("DEPRECATION")
                    startActivityForResult(
                        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:$packageName")),
                        INSTALL_PERM_REQUEST
                    )
                }
                .setNegativeButton("Annulla", null)
                .show()
            return
        }
        installWithPackageInstaller(file, GATEWAY_PACKAGE)
    }

    private fun installWithPackageInstaller(file: java.io.File, targetPkg: String) {
        try {
            val pi     = packageManager.packageInstaller
            val params = android.content.pm.PackageInstaller.SessionParams(
                android.content.pm.PackageInstaller.SessionParams.MODE_FULL_INSTALL
            )
            // Note: setAppPackageName() is intentionally omitted — it causes STATUS_FAILURE (1)
            // on some OEM/Android versions even when the package name is correct.
            val sessionId = pi.createSession(params)
            val session   = pi.openSession(sessionId)
            try {
                file.inputStream().use { input ->
                    session.openWrite("package", 0, file.length()).use { out ->
                        input.copyTo(out)
                        session.fsync(out)
                    }
                }
            } catch (e: Exception) {
                try { session.abandon() } catch (_: Exception) {}
                throw e
            }
            // Do NOT close() the session after commit — it is now owned by the system.

            val action = "it.dadaloop.evershelf.kiosk.SETUP_INSTALL_$sessionId"
            val resultReceiver = object : BroadcastReceiver() {
                override fun onReceive(ctx: Context?, intent: Intent?) {
                    val status = intent?.getIntExtra(
                        android.content.pm.PackageInstaller.EXTRA_STATUS,
                        android.content.pm.PackageInstaller.STATUS_FAILURE
                    ) ?: android.content.pm.PackageInstaller.STATUS_FAILURE

                    when (status) {
                        android.content.pm.PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                            // Do NOT unregister here — on Android 11+ the final result
                            // (STATUS_SUCCESS or STATUS_FAILURE) arrives as a second broadcast
                            // to this same receiver AFTER the user confirms the dialog.
                            @Suppress("DEPRECATION")
                            val confirmIntent = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU)
                                intent?.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java)
                            else intent?.getParcelableExtra(Intent.EXTRA_INTENT)
                            if (confirmIntent != null) {
                                pendingInstallFile = file
                                pendingInstallPkg  = targetPkg
                                setGatewayUI("⏳", getString(R.string.install_installing),
                                    getString(R.string.install_confirm_detail), 0xFF94a3b8.toInt(),
                                    btnEnabled = false, progress = -1)
                                @Suppress("DEPRECATION")
                                startActivityForResult(confirmIntent, INSTALL_CONFIRM_REQUEST)
                            } else {
                                // No confirmation intent — give up gracefully
                                unregisterReceiver(this)
                                setGatewayUI("❌", getString(R.string.install_error_install),
                                    "No confirmation intent", 0xFFf87171.toInt())
                            }
                        }
                        android.content.pm.PackageInstaller.STATUS_SUCCESS -> {
                            unregisterReceiver(this)
                            setGatewayUI("✅", getString(R.string.install_success),
                                getString(R.string.install_success_detail), 0xFF34d399.toInt(),
                                btnEnabled = false)
                            Handler(Looper.getMainLooper()).postDelayed({ checkGatewayStatus() }, 1500)
                        }
                        android.content.pm.PackageInstaller.STATUS_FAILURE_INCOMPATIBLE,
                        android.content.pm.PackageInstaller.STATUS_FAILURE_CONFLICT -> {
                            unregisterReceiver(this)
                            runOnUiThread { offerUninstallAndRetry(file, targetPkg) }
                        }
                        -1 /* STATUS_FAILURE_ABORTED */ -> {
                            // User cancelled the install confirmation dialog — just reset UI
                            unregisterReceiver(this)
                            runOnUiThread { checkGatewayStatus() }
                        }
                        android.content.pm.PackageInstaller.STATUS_FAILURE -> {
                            // Generic failure (status=1): PackageInstaller can't install on this
                            // device/config. Fall back to system Intent.ACTION_VIEW installer UI.
                            unregisterReceiver(this)
                            ErrorReporter.reportMessage(
                                "install_failure",
                                "PackageInstaller STATUS_FAILURE=1, trying ACTION_VIEW fallback",
                                mapOf(
                                    "pkg"     to targetPkg,
                                    "apk_kb"  to (file.length() / 1024),
                                    "android" to Build.VERSION.SDK_INT,
                                    "device"  to buildDeviceLabel()
                                ),
                                forceReport = true
                            )
                            runOnUiThread { tryFallbackInstall(file, targetPkg) }
                        }
                        else -> {
                            unregisterReceiver(this)
                            val msg = intent?.getStringExtra(
                                android.content.pm.PackageInstaller.EXTRA_STATUS_MESSAGE
                            ) ?: ""
                            val deviceLabel = buildDeviceLabel()
                            val hint = when (status) {
                                2    -> "Bloccato da policy o da un'altra installazione in corso"
                                3    -> "Annullato"
                                4    -> "APK non valido o corrotto"
                                5    -> "Conflitto: versione precedente con firma diversa"
                                6    -> "Spazio insufficiente"
                                7    -> "Incompatibile con questa versione di Android"
                                else -> "Errore sconosciuto (status=$status)"
                            }
                            val diagInfo = buildString {
                                appendLine("❌ Status $status: $hint")
                                if (msg.isNotEmpty()) appendLine("Dettaglio: $msg")
                                appendLine("Pacchetto: $targetPkg")
                                appendLine("APK: ${file.length() / 1024} KB")
                                appendLine("Android: ${Build.VERSION.SDK_INT} (${Build.VERSION.RELEASE})")
                                appendLine("Dispositivo: $deviceLabel")
                            }
                            setGatewayUI("❌", getString(R.string.install_error_install),
                                diagInfo.trim(), 0xFFf87171.toInt())
                            ErrorReporter.reportMessage(
                                "install_failure",
                                "PackageInstaller status=$status pkg=$targetPkg android=${Build.VERSION.SDK_INT}",
                                mapOf(
                                    "pkg"     to targetPkg,
                                    "status"  to status,
                                    "hint"    to hint,
                                    "msg"     to msg,
                                    "apk_kb"  to (file.length() / 1024),
                                    "android" to Build.VERSION.SDK_INT,
                                    "device"  to deviceLabel
                                ),
                                forceReport = true
                            )
                            val pkgInstalled = try {
                                packageManager.getPackageInfo(targetPkg, 0); true
                            } catch (_: Exception) { false }
                            runOnUiThread {
                                if (pkgInstalled) {
                                    offerUninstallAndRetry(file, targetPkg)
                                } else {
                                    AlertDialog.Builder(this@SetupActivity)
                                        .setTitle("❌ Installazione fallita (status=$status)")
                                        .setMessage(diagInfo.trim())
                                        .setPositiveButton("Riprova") { _, _ ->
                                            installWithPackageInstaller(file, targetPkg)
                                        }
                                        .setNeutralButton("Salta") { _, _ ->
                                            checkGatewayStatus()
                                        }
                                        .show()
                                }
                            }
                        }
                    }
                }
            }
            val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) RECEIVER_NOT_EXPORTED else 0
            registerReceiver(resultReceiver, IntentFilter(action), flags)
            val pi2 = PendingIntent.getBroadcast(
                this, sessionId,
                Intent(action).setPackage(packageName),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            session.commit(pi2.intentSender)
            setGatewayUI("⏳", getString(R.string.install_installing), "", 0xFF94a3b8.toInt(), btnEnabled = false, progress = -1)
        } catch (e: Exception) {
            setGatewayUI("❌", getString(R.string.install_error_install), e.message ?: "", 0xFFf87171.toInt())
            ErrorReporter.reportMessage("install_packager_exception",
                "installWithPackageInstaller exception for $targetPkg: ${e.message}",
                mapOf("android" to Build.VERSION.SDK_INT, "apk_kb" to (file.length() / 1024)))
        }
    }

    private fun offerUninstallAndRetry(file: java.io.File, pkg: String) {
        pendingInstallFile = file
        pendingInstallPkg  = pkg
        AlertDialog.Builder(this)
            .setTitle("⚠️ Conflitto firma APK")
            .setMessage("L'app installata usa una firma diversa. Devi prima disinstallare la versione precedente.\n\nDisinstalla ora? L'installazione riprenderà automaticamente.")
            .setPositiveButton("Disinstalla") { _, _ ->
                @Suppress("DEPRECATION")
                startActivityForResult(
                    Intent(Intent.ACTION_DELETE, Uri.parse("package:$pkg")),
                    UNINSTALL_REQUEST
                )
            }
            .setNegativeButton("Annulla", null)
            .show()
    }

    // ── Summary / Finish ─────────────────────────────────────────────────

    private fun buildSummary() {
        val url       = prefs.getString(KEY_URL, "") ?: ""
        val hasScale  = prefs.getBoolean(KEY_HAS_SCALE, false)
        val screensOn = setupSwitchScreensaver.isChecked
        val gwOk      = hasScale && isGatewayInstalled()
        val lang      = prefs.getString(KEY_LANGUAGE, "it") ?: "it"
        val langLabel = when (lang) { "en" -> "English 🇬🇧"; "de" -> "Deutsch 🇩🇪"; else -> "Italiano 🇮🇹" }
        val sb = StringBuilder()
        sb.appendLine("🌐 ${getString(R.string.summary_lang)}: $langLabel")
        if (url.isNotEmpty()) sb.appendLine("🖥️ Server: $url")
        sb.appendLine(when {
            gwOk     -> "✅ Scale Gateway: ${getString(R.string.wizard_gateway_installed)}"
            hasScale -> "⚠️ Scale Gateway: ${getString(R.string.wizard_gateway_not_installed)}"
            else     -> "⏭ ${getString(R.string.summary_scale_skip)}"
        })
        sb.appendLine(if (screensOn) "🌙 ${getString(R.string.summary_screensaver_on)}" else "💡 ${getString(R.string.summary_screensaver_off)}")
        summaryText.text = sb.toString().trimEnd()
    }

    private fun finishSetup() {
        prefs.edit().putBoolean(KEY_SETUP_COMPLETE, true).apply()
        // ── Sync settings to webapp API ─────────────────────────────────────────
        // Always push: screensaver_enabled (in-app clock overlay preference).
        // Conditionally add: scale settings when gateway is installed.
        val baseUrl = (prefs.getString(KEY_URL, "") ?: "").trimEnd('/')
        if (baseUrl.isNotEmpty()) {
            val hasScale    = prefs.getBoolean(KEY_HAS_SCALE, false) && isGatewayInstalled()
            val screensaver = prefs.getBoolean(KEY_SCREENSAVER, false)
            Thread {
                try {
                    val url  = "$baseUrl/api/index.php?action=save_settings"
                    val body = buildString {
                        append("{\"screensaver_enabled\":$screensaver")
                        if (hasScale) {
                            append(",\"scale_enabled\":true,\"scale_gateway_url\":\"ws://127.0.0.1:8765\"")
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

    // ── Activity Results ─────────────────────────────────────────────────

    @Suppress("DEPRECATION")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        when (requestCode) {
            INSTALL_PERM_REQUEST -> {
                // Returned from "Install unknown apps" settings for this app.
                // pendingInstallFile is set when coming from installApk() permission check,
                // pendingApkDownloadUrl is set when coming from triggerApkDownload().
                val pendingFile = pendingInstallFile
                if (pendingFile != null && pendingFile.exists()) {
                    installApk(pendingFile)
                } else if (pendingApkDownloadUrl.isNotEmpty()) {
                    triggerApkDownload(pendingApkDownloadUrl)
                }
            }
            INSTALL_CONFIRM_REQUEST -> {
                // On Android 11+ the final install result (STATUS_SUCCESS / STATUS_FAILURE)
                // arrives via the BroadcastReceiver, not via onActivityResult.
                // RESULT_OK  = user tapped "Install" in the system dialog (not "install succeeded")
                // RESULT_CANCELED = user pressed Back without confirming
                if (resultCode != RESULT_OK) {
                    // User backed out of the confirmation — BroadcastReceiver will receive
                    // STATUS_FAILURE_ABORTED (-1) and reset the UI automatically.
                    // No action needed here.
                }
            }
            UNINSTALL_REQUEST -> {
                val f   = pendingInstallFile
                val pkg = pendingInstallPkg
                if (f != null && f.exists() && pkg.isNotEmpty()) {
                    Handler(Looper.getMainLooper()).postDelayed({ installWithPackageInstaller(f, pkg) }, 600)
                }
            }
            INSTALL_FALLBACK_REQUEST -> {
                // System package installer returned — check if the package is now installed.
                // Whether the user pressed "Done" or "Open", bring setup back to foreground.
                Handler(Looper.getMainLooper()).postDelayed({
                    // Bring this activity back to front in case user pressed "Open"
                    val bringFront = Intent(this, SetupActivity::class.java).apply {
                        flags = Intent.FLAG_ACTIVITY_REORDER_TO_FRONT
                    }
                    startActivity(bringFront)
                    val installed = try { packageManager.getPackageInfo(pendingInstallPkg, 0); true } catch (_: Exception) { false }
                    if (installed) {
                        setGatewayUI("✅", getString(R.string.install_success),
                            getString(R.string.install_success_detail), 0xFF34d399.toInt(), btnEnabled = false)
                        Handler(Looper.getMainLooper()).postDelayed({ checkGatewayStatus() }, 1500)
                    } else {
                        // Install failed or user cancelled. Show an explicit retry button
                        // that re-launches the system installer directly (skipping PackageInstaller,
                        // which is known to give STATUS=1 on this device).
                        val retryFile = pendingInstallFile
                        val retryPkg  = pendingInstallPkg
                        setGatewayUI(
                            "⚠️",
                            "Installazione non completata",
                            "L'app non risulta installata. Premi il pulsante sotto per riprovare.",
                            0xFFfbbf24.toInt()
                        )
                        btnInstallGateway.visibility = View.VISIBLE
                        btnInstallGateway.text = "🔄  Riprova installazione"
                        btnInstallGateway.setOnClickListener {
                            // Reset button back to default before retrying
                            btnInstallGateway.text = "📥  Installa Scale Gateway"
                            btnInstallGateway.setOnClickListener {
                                pendingApkDownloadUrl = GATEWAY_DOWNLOAD_URL
                                triggerApkDownload(GATEWAY_DOWNLOAD_URL)
                            }
                            if (retryFile != null && retryFile.exists()) {
                                tryFallbackInstall(retryFile, retryPkg)
                            } else {
                                pendingApkDownloadUrl = GATEWAY_DOWNLOAD_URL
                                triggerApkDownload(GATEWAY_DOWNLOAD_URL)
                            }
                        }
                    }
                }, 800)
            }
        }
    }

    private fun tryFallbackInstall(file: java.io.File, targetPkg: String) {
        try {
            val uri = androidx.core.content.FileProvider.getUriForFile(
                this, "$packageName.provider", file
            )
            val intent = Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(uri, "application/vnd.android.package-archive")
                // Note: do NOT add FLAG_ACTIVITY_NEW_TASK — it breaks startActivityForResult:
                // Android would return RESULT_CANCELED immediately without waiting for the user.
                flags = Intent.FLAG_GRANT_READ_URI_PERMISSION
            }
            pendingInstallFile = file
            pendingInstallPkg  = targetPkg

            // Warn user: after installation Android shows "Open" and "Done" buttons.
            // Opening the gateway app directly would leave the kiosk in the background.
            AlertDialog.Builder(this)
                .setTitle("📦 Installazione in corso")
                .setMessage(
                    "Quando Android mostra la schermata di installazione completata:\n\n" +
                    "✅  Premi  \"Fine\"  per tornare al setup\n" +
                    "⛔  NON premere  \"Apri\"  — l'app potrebbe non funzionare correttamente se aperta direttamente"
                )
                .setPositiveButton("Ho capito, procedi") { _, _ ->
                    setGatewayUI("⏳", getString(R.string.install_installing),
                        "Conferma l'installazione nella finestra di sistema...",
                        0xFF94a3b8.toInt(), btnEnabled = false, progress = -1)
                    @Suppress("DEPRECATION")
                    startActivityForResult(intent, INSTALL_FALLBACK_REQUEST)
                }
                .setCancelable(false)
                .show()
        } catch (e: Exception) {
            val deviceLabel = buildDeviceLabel()
            val diagInfo = buildString {
                appendLine("❌ PackageInstaller status=1 e fallback non riuscito")
                appendLine("Errore: ${e.message}")
                appendLine("Android: ${Build.VERSION.SDK_INT} (${Build.VERSION.RELEASE})")
                appendLine("Dispositivo: $deviceLabel")
            }
            setGatewayUI("❌", getString(R.string.install_error_install),
                diagInfo.trim(), 0xFFf87171.toInt())
            ErrorReporter.reportMessage(
                "install_fallback_exception",
                "tryFallbackInstall failed: ${e.message}",
                mapOf("android" to Build.VERSION.SDK_INT, "device" to deviceLabel),
                forceReport = true
            )
        }
    }

    private fun buildDeviceLabel(): String {
        val mfr   = Build.MANUFACTURER.takeIf { it.isNotBlank() && it != "unknown" }
            ?: Build.PRODUCT.takeIf { it.isNotBlank() && it != "unknown" }
            ?: Build.BOARD
        val model = Build.MODEL.takeIf { it.isNotBlank() && it != "unknown" }
            ?: Build.HARDWARE
        return "$mfr $model"
    }
}
