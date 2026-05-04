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
import android.net.Uri
import android.net.wifi.WifiManager
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
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.google.android.material.button.MaterialButton
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager

/**
 * Full setup wizard — runs BEFORE KioskActivity locks the screen.
 * The user can always exit (finishAffinity) via the ✕ button.
 *
 * Steps:
 *  0 — Welcome / intro / privacy
 *  1 — Permissions rationale + grant
 *  2 — Server URL + auto-discovery + connection test
 *  3 — Smart scale question → gateway info + install
 *  4 — Done
 */
class SetupActivity : AppCompatActivity() {

    private lateinit var prefs: SharedPreferences
    private var currentStep = 0

    // Step containers
    private lateinit var stepWelcome:     LinearLayout
    private lateinit var stepPermissions: LinearLayout
    private lateinit var stepServer:      LinearLayout
    private lateinit var stepScale:       LinearLayout
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
    private lateinit var btnInstallGateway:  MaterialButton
    private lateinit var gatewayProgressBar: ProgressBar
    private lateinit var gatewayProgressText: TextView
    private lateinit var step3NextButtons:   LinearLayout

    // Done step
    private lateinit var summaryText: TextView

    // Permissions step
    private lateinit var permsGrantedCard: LinearLayout

    // APK install state (for gateway)
    private var pendingApkDownloadUrl = ""
    private var pendingInstallFile: java.io.File? = null
    private var pendingInstallPkg  = ""
    private val pollHandler = Handler(Looper.getMainLooper())
    private var activeDownloadId: Long = -1

    // Auto-discover cancellation flag
    private val discoverCancelled = AtomicBoolean(false)

    companion object {
        private const val PREFS_NAME   = "evershelf_kiosk"
        private const val KEY_URL      = "evershelf_url"
        private const val KEY_SETUP_COMPLETE = "setup_complete"
        private const val KEY_HAS_SCALE      = "has_scale"
        private const val GATEWAY_PACKAGE = "it.dadaloop.evershelf.scalegate"
        private const val GATEWAY_DOWNLOAD_URL =
            "https://github.com/dadaloop82/EverShelf/releases/latest/download/evershelf-scale-gateway.apk"
        private const val INSTALL_PERM_REQUEST    = 2001
        private const val INSTALL_CONFIRM_REQUEST = 2002
        private const val UNINSTALL_REQUEST       = 2003
        private const val PERMISSION_REQUEST_CODE = 2004
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_setup)
        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        bindViews()
        showStep(0)
    }

    override fun onBackPressed() {
        when (currentStep) {
            0 -> confirmExit()
            else -> showStep(currentStep - 1)
        }
    }

    override fun onDestroy() {
        pollHandler.removeCallbacksAndMessages(null)
        discoverCancelled.set(true)
        super.onDestroy()
    }

    // ── Binding ────────────────────────────────────────────────────────────

    private fun bindViews() {
        progressDots     = findViewById(R.id.setupProgressDots)
        stepWelcome      = findViewById(R.id.stepWelcome)
        stepPermissions  = findViewById(R.id.stepPermissions)
        stepServer       = findViewById(R.id.stepServer)
        stepScale        = findViewById(R.id.stepScale)
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
        gatewayStatusDetail = findViewById(R.id.gatewayStatusDetail)
        btnInstallGateway   = findViewById(R.id.btnInstallGateway)
        gatewayProgressBar  = findViewById(R.id.gatewayProgressBar)
        gatewayProgressText = findViewById(R.id.gatewayProgressText)
        step3NextButtons    = findViewById(R.id.step3NextButtons)

        // Done step
        summaryText = findViewById(R.id.setupSummaryText)

        // Permissions step
        permsGrantedCard = findViewById(R.id.permsGrantedCard)

        // Pre-fill saved URL
        val savedUrl = prefs.getString(KEY_URL, "") ?: ""
        if (savedUrl.isNotEmpty()) urlEdit.setText(savedUrl)

        // ── Welcome ──────────────────────────────────────────────────────
        findViewById<MaterialButton>(R.id.btnSetupExit).setOnClickListener { confirmExit() }
        findViewById<MaterialButton>(R.id.btnWelcomeStart).setOnClickListener { showStep(1) }

        // ── Permissions ──────────────────────────────────────────────────
        findViewById<MaterialButton>(R.id.btnGrantPerms).setOnClickListener  { requestPermissions() }
        findViewById<MaterialButton>(R.id.btnPermsBack).setOnClickListener   { showStep(0) }
        findViewById<MaterialButton>(R.id.btnPermsNext).setOnClickListener   { showStep(2) }

        // ── Server ───────────────────────────────────────────────────────
        btnDiscover.setOnClickListener { autoDiscover() }
        btnTestUrl.setOnClickListener  { testConnection() }
        findViewById<MaterialButton>(R.id.btnServerBack).setOnClickListener { showStep(1) }
        findViewById<MaterialButton>(R.id.btnServerNext).setOnClickListener {
            val url = urlEdit.text.toString().trim()
            if (url.isEmpty()) {
                showUrlStatus(getString(R.string.setup_enter_url), false)
                return@setOnClickListener
            }
            prefs.edit().putString(KEY_URL, url).apply()
            ErrorReporter.init(this, url)
            showStep(3)
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
            showStep(4)
        }
        btnInstallGateway.setOnClickListener {
            pendingApkDownloadUrl = GATEWAY_DOWNLOAD_URL
            triggerApkDownload(GATEWAY_DOWNLOAD_URL)
        }
        findViewById<MaterialButton>(R.id.btnScaleBack).setOnClickListener { showStep(2) }
        findViewById<MaterialButton>(R.id.btnScaleNext).setOnClickListener {
            prefs.edit().putBoolean(KEY_HAS_SCALE, true).apply()
            showStep(4)
        }

        // ── Done ──────────────────────────────────────────────────────────
        findViewById<MaterialButton>(R.id.btnLaunch).setOnClickListener { finishSetup() }
    }

    // ── Step navigation ───────────────────────────────────────────────────

    private fun showStep(step: Int) {
        currentStep = step
        stepWelcome.visibility     = if (step == 0) View.VISIBLE else View.GONE
        stepPermissions.visibility = if (step == 1) View.VISIBLE else View.GONE
        stepServer.visibility      = if (step == 2) View.VISIBLE else View.GONE
        stepScale.visibility       = if (step == 3) View.VISIBLE else View.GONE
        stepDone.visibility        = if (step == 4) View.VISIBLE else View.GONE

        updateProgressDots()

        // Reset scale step when entering it
        if (step == 3) {
            scaleQuestionCard.visibility  = View.VISIBLE
            gatewayInfoCard.visibility    = View.GONE
            gatewayInstallCard.visibility = View.GONE
            step3NextButtons.visibility   = View.GONE
        }

        // Build summary when entering done step
        if (step == 4) buildSummary()

        // Cancel auto-discover when leaving server step
        if (step != 2) discoverCancelled.set(true)

        // Scroll to top
        try { findViewById<ScrollView>(R.id.setupScrollView).scrollTo(0, 0) } catch (_: Exception) {}
    }

    private fun updateProgressDots() {
        progressDots.removeAllViews()
        // 4 dots (steps 1–4); step 0 welcome uses no dots
        val active = maxOf(currentStep, 1)
        val density = resources.displayMetrics.density
        for (i in 1..4) {
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
            // Already granted — show confirmation and allow next
            permsGrantedCard.visibility = View.VISIBLE
        } else {
            ActivityCompat.requestPermissions(this, needed.toTypedArray(), PERMISSION_REQUEST_CODE)
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == PERMISSION_REQUEST_CODE) {
            permsGrantedCard.visibility = View.VISIBLE
            // Proceed to next step regardless — user can always grant later
            Handler(Looper.getMainLooper()).postDelayed({ showStep(2) }, 600)
        }
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

    @Suppress("DEPRECATION")
    private fun autoDiscover() {
        discoverCancelled.set(false)
        btnDiscover.isEnabled = false
        btnDiscover.text = getString(R.string.setup_discovering)
        discoverStatus.visibility = View.VISIBLE
        discoverStatus.text = getString(R.string.setup_discovering_detail)
        discoverStatus.setTextColor(0xFF94a3b8.toInt())

        // Determine local subnet
        val wifiMgr  = applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
        val ipInt    = wifiMgr.connectionInfo.ipAddress
        val subnets  = mutableListOf<String>()
        if (ipInt != 0) {
            val a = (ipInt shr  0) and 0xFF
            val b = (ipInt shr  8) and 0xFF
            val c = (ipInt shr 16) and 0xFF
            subnets += "$a.$b.$c"
        }
        // Always include common subnets as fallback
        for (s in listOf("192.168.1", "192.168.0", "192.168.2", "10.0.0")) {
            if (!subnets.contains(s)) subnets += s
        }

        val ports = listOf(80, 8080)
        val paths = listOf(
            "/api/index.php?action=get_settings",
            "/dispensa/api/index.php?action=get_settings",
            "/evershelf/api/index.php?action=get_settings"
        )
        val executor = Executors.newFixedThreadPool(40)
        val found    = AtomicBoolean(false)

        Thread {
            val futures = mutableListOf<java.util.concurrent.Future<String?>>()
            outer@ for (subnet in subnets) {
                for (i in 1..254) {
                    if (discoverCancelled.get() || found.get()) break@outer
                    val ip = "$subnet.$i"
                    for (port in ports) {
                        if (discoverCancelled.get() || found.get()) break@outer
                        futures += executor.submit<String?> submit@{
                            if (discoverCancelled.get() || found.get()) return@submit null
                            val scheme = if (port == 443 || port == 8443) "https" else "http"
                            for (path in paths) {
                                val urlStr = "$scheme://$ip:$port$path"
                                try {
                                    val conn = openConn(urlStr) ?: continue
                                    val code = conn.responseCode
                                    if (code in 200..399) {
                                        val body = conn.inputStream.bufferedReader().readText()
                                        conn.disconnect()
                                        if (body.contains("gemini_key_set") || body.contains("\"success\"")) {
                                            val base = urlStr.substringBefore("/api/")
                                            return@submit "$base/"
                                        }
                                    } else conn.disconnect()
                                } catch (_: Exception) {}
                            }
                            null
                        }
                    }
                }
            }

            // Collect results
            for (f in futures) {
                if (discoverCancelled.get()) break
                val result = try { f.get(4, TimeUnit.SECONDS) } catch (_: Exception) { null }
                if (result != null && found.compareAndSet(false, true)) {
                    runOnUiThread {
                        urlEdit.setText(result)
                        discoverStatus.text = "✅ ${getString(R.string.setup_server_found)}: $result"
                        discoverStatus.setTextColor(0xFF34d399.toInt())
                        showUrlStatus("✅ ${getString(R.string.setup_server_found)}", true)
                        btnDiscover.isEnabled = true
                        btnDiscover.text = getString(R.string.setup_discover_btn)
                    }
                    break
                }
            }
            executor.shutdown()

            if (!found.get() && !discoverCancelled.get()) {
                runOnUiThread {
                    discoverStatus.text = getString(R.string.setup_discover_not_found)
                    discoverStatus.setTextColor(0xFFf87171.toInt())
                    btnDiscover.isEnabled = true
                    btnDiscover.text = getString(R.string.setup_discover_btn)
                }
            } else if (!found.get()) {
                runOnUiThread {
                    btnDiscover.isEnabled = true
                    btnDiscover.text = getString(R.string.setup_discover_btn)
                }
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
            gatewayStatusDetail.text = getString(R.string.wizard_gateway_installed_detail)
            gatewayStatusDetail.setTextColor(0xFF34d399.toInt())
            btnInstallGateway.visibility = View.GONE
            gatewayProgressBar.visibility = View.GONE
            gatewayProgressText.visibility = View.GONE
        } else {
            gatewayStatusIcon.text = "📲"
            gatewayStatusText.text = getString(R.string.wizard_gateway_not_installed)
            gatewayStatusDetail.text = getString(R.string.wizard_gateway_not_installed_detail)
            gatewayStatusDetail.setTextColor(0xFFfbbf24.toInt())
            btnInstallGateway.visibility = View.VISIBLE
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
        installWithPackageInstaller(file, GATEWAY_PACKAGE)
    }

    private fun installWithPackageInstaller(file: java.io.File, targetPkg: String) {
        try {
            val pi     = packageManager.packageInstaller
            val params = android.content.pm.PackageInstaller.SessionParams(
                android.content.pm.PackageInstaller.SessionParams.MODE_FULL_INSTALL
            )
            params.setAppPackageName(targetPkg)
            val sessionId = pi.createSession(params)
            pi.openSession(sessionId).use { session ->
                file.inputStream().use { input ->
                    session.openWrite("package", 0, file.length()).use { out ->
                        input.copyTo(out)
                        session.fsync(out)
                    }
                }
                val action = "it.dadaloop.evershelf.kiosk.SETUP_INSTALL_$sessionId"
                val resultReceiver = object : BroadcastReceiver() {
                    override fun onReceive(ctx: Context?, intent: Intent?) {
                        unregisterReceiver(this)
                        val status = intent?.getIntExtra(
                            android.content.pm.PackageInstaller.EXTRA_STATUS,
                            android.content.pm.PackageInstaller.STATUS_FAILURE
                        ) ?: android.content.pm.PackageInstaller.STATUS_FAILURE
                        when (status) {
                            android.content.pm.PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                                @Suppress("DEPRECATION")
                                val confirmIntent = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU)
                                    intent?.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java)
                                else intent?.getParcelableExtra(Intent.EXTRA_INTENT)
                                if (confirmIntent != null) {
                                    pendingInstallFile = file
                                    pendingInstallPkg  = targetPkg
                                    @Suppress("DEPRECATION")
                                    startActivityForResult(confirmIntent, INSTALL_CONFIRM_REQUEST)
                                }
                            }
                            android.content.pm.PackageInstaller.STATUS_SUCCESS -> {
                                setGatewayUI("✅", getString(R.string.install_success), getString(R.string.install_success_detail), 0xFF34d399.toInt(), btnEnabled = false)
                                Handler(Looper.getMainLooper()).postDelayed({ checkGatewayStatus() }, 1500)
                            }
                            android.content.pm.PackageInstaller.STATUS_FAILURE_INCOMPATIBLE,
                            android.content.pm.PackageInstaller.STATUS_FAILURE_CONFLICT -> {
                                runOnUiThread { offerUninstallAndRetry(file, targetPkg) }
                            }
                            else -> {
                                val msg = intent?.getStringExtra(android.content.pm.PackageInstaller.EXTRA_STATUS_MESSAGE) ?: "status=$status"
                                setGatewayUI("❌", getString(R.string.install_error_install), msg, 0xFFf87171.toInt())
                                val pkgInstalled = try { packageManager.getPackageInfo(targetPkg, 0); true } catch (_: Exception) { false }
                                if (pkgInstalled) runOnUiThread { offerUninstallAndRetry(file, targetPkg) }
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
            }
            setGatewayUI("⏳", getString(R.string.install_installing), "", 0xFF94a3b8.toInt(), btnEnabled = false, progress = -1)
        } catch (e: Exception) {
            setGatewayUI("❌", getString(R.string.install_error_install), e.message ?: "", 0xFFf87171.toInt())
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
        val url      = prefs.getString(KEY_URL, "") ?: ""
        val hasScale = prefs.getBoolean(KEY_HAS_SCALE, false)
        val gwOk     = hasScale && isGatewayInstalled()
        val sb = StringBuilder()
        if (url.isNotEmpty()) sb.appendLine("🌐 Server: $url")
        sb.appendLine(when {
            gwOk     -> "✅ Scale Gateway: installato"
            hasScale -> "⚠️ Scale Gateway: non ancora installato"
            else     -> "⏭ Bilancia: non configurata"
        })
        summaryText.text = sb.toString().trimEnd()
    }

    private fun finishSetup() {
        prefs.edit().putBoolean(KEY_SETUP_COMPLETE, true).apply()
        setResult(RESULT_OK)
        finish()
    }

    // ── Activity Results ─────────────────────────────────────────────────

    @Suppress("DEPRECATION")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        when (requestCode) {
            INSTALL_PERM_REQUEST -> {
                if (pendingApkDownloadUrl.isNotEmpty()) triggerApkDownload(pendingApkDownloadUrl)
            }
            INSTALL_CONFIRM_REQUEST -> {
                if (resultCode == RESULT_OK) {
                    setGatewayUI("✅", getString(R.string.install_success), getString(R.string.install_success_detail), 0xFF34d399.toInt(), btnEnabled = false)
                    Handler(Looper.getMainLooper()).postDelayed({ checkGatewayStatus() }, 1500)
                } else {
                    val f   = pendingInstallFile
                    val pkg = pendingInstallPkg
                    if (f != null && f.exists() && pkg.isNotEmpty()) {
                        runOnUiThread {
                            AlertDialog.Builder(this)
                                .setTitle("⚠️ Installazione non riuscita")
                                .setMessage("Se c'è un conflitto di firma, devi disinstallare la versione precedente.\n\nDisinstalla ora?")
                                .setPositiveButton("Disinstalla") { _, _ ->
                                    startActivityForResult(Intent(Intent.ACTION_DELETE, Uri.parse("package:$pkg")), UNINSTALL_REQUEST)
                                }
                                .setNegativeButton("Annulla", null).show()
                        }
                    }
                }
            }
            UNINSTALL_REQUEST -> {
                val f   = pendingInstallFile
                val pkg = pendingInstallPkg
                if (f != null && f.exists() && pkg.isNotEmpty()) {
                    Handler(Looper.getMainLooper()).postDelayed({ installWithPackageInstaller(f, pkg) }, 600)
                }
            }
        }
    }
}
