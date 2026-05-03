package it.dadaloop.evershelf.kiosk

import android.annotation.SuppressLint
import android.Manifest
import android.app.ActivityManager
import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.SharedPreferences
import android.app.PendingIntent
import android.content.pm.PackageInstaller
import android.content.pm.PackageManager
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.net.http.SslError
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.speech.tts.TextToSpeech
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.webkit.ConsoleMessage
import android.webkit.JavascriptInterface
import android.webkit.PermissionRequest
import android.webkit.SslErrorHandler
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.EditText
import android.widget.ImageButton
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import android.widget.ProgressBar
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.google.android.material.button.MaterialButton
import org.json.JSONObject
import java.net.URL
import java.util.Locale
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager

class KioskActivity : AppCompatActivity() {

    private lateinit var prefs: SharedPreferences
    private var currentStep = 1

    // Native TTS engine (Android) — used by the JS bridge so the WebView
    // doesn't depend on Web Speech API voices being installed.
    private var tts: TextToSpeech? = null
    private var ttsReady = false

    // Views
    private lateinit var splashContainer: LinearLayout
    private lateinit var wizardContainer: ScrollView
    private lateinit var webView: WebView
    private lateinit var btnSettings: ImageButton
    private lateinit var step1: LinearLayout
    private lateinit var step2: LinearLayout
    private lateinit var step3: LinearLayout
    private lateinit var stepIndicator: LinearLayout
    private lateinit var wizardUrl: EditText
    private lateinit var urlStatus: TextView
    private lateinit var scaleStatusIcon: TextView
    private lateinit var scaleStatusText: TextView
    private lateinit var scaleStatusDetail: TextView
    private lateinit var scaleQuestionLayout: LinearLayout
    private lateinit var step3BottomButtons: LinearLayout
    // Update banner
    private lateinit var updateBanner: LinearLayout
    private lateinit var tvUpdateMessage: TextView
    private lateinit var btnInstallUpdate: MaterialButton
    private lateinit var btnDismissUpdate: MaterialButton
    private lateinit var downloadProgressBar: ProgressBar
    private lateinit var downloadProgressText: TextView
    private lateinit var bannerProgressBar: ProgressBar
    private var pendingApkDownloadUrl: String = ""
    private var pendingInstallFile: java.io.File? = null
    private var pendingInstallPkg: String = ""
    /** The button that triggered the current download/install — updated throughout the flow. */
    private var activeInstallBtn: MaterialButton? = null
    /** Handler for the 500 ms download-progress polling loop. */
    private val pollHandler = Handler(Looper.getMainLooper())
    private var activeDownloadId: Long = -1

    // Triple-tap to exit
    private var tapCount = 0
    private val tapHandler = Handler(Looper.getMainLooper())
    private val tapResetRunnable = Runnable { tapCount = 0 }

    // File chooser
    private var fileChooserCallback: ValueCallback<Array<Uri>>? = null

    // Pending WebView permission request (waiting for runtime grant)
    private var pendingWebPermission: PermissionRequest? = null

    companion object {
        private const val FILE_CHOOSER_REQUEST    = 1002
        private const val PERMISSION_REQUEST_CODE = 1003
        private const val INSTALL_PERM_REQUEST    = 1004   // ACTION_MANAGE_UNKNOWN_APP_SOURCES
        private const val INSTALL_CONFIRM_REQUEST = 1005   // system installer confirm dialog
        private const val UNINSTALL_REQUEST       = 1006   // ACTION_DELETE → auto-retry install
        private const val PREFS_NAME = "evershelf_kiosk"
        private const val KEY_URL = "evershelf_url"
        private const val KEY_SETUP_COMPLETE = "setup_complete"
        private const val KEY_HAS_SCALE = "has_scale"
        private const val GATEWAY_PACKAGE = "it.dadaloop.evershelf.scalegate"
        private const val GATEWAY_DOWNLOAD_URL = "https://github.com/dadaloop82/EverShelf/releases/latest/download/evershelf-scale-gateway.apk"
        private const val KIOSK_DOWNLOAD_URL = "https://github.com/dadaloop82/EverShelf/releases/latest/download/evershelf-kiosk.apk"
        private const val SPLASH_DURATION = 1500L
        private const val GITHUB_RELEASES_API = "https://api.github.com/repos/dadaloop82/EverShelf/releases/latest"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_kiosk)

        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        bindViews()
        enterImmersiveMode()
        enableKioskLock()
        requestAllPermissions()

        // Initialise centralised error reporter as early as possible so the
        // UncaughtExceptionHandler is installed before any background work starts.
        val savedUrl = prefs.getString(KEY_URL, "") ?: ""
        ErrorReporter.init(this, savedUrl)

        // Initialise native TTS engine so the JS bridge works even when
        // Web Speech API voices are unavailable in the Android WebView.
        tts = TextToSpeech(this) { status ->
            if (status == TextToSpeech.SUCCESS) {
                val it = tts?.setLanguage(Locale.ITALIAN)
                if (it == TextToSpeech.LANG_MISSING_DATA || it == TextToSpeech.LANG_NOT_SUPPORTED) {
                    // Italian data missing — fall back to device default
                    tts?.language = Locale.getDefault()
                }
                ttsReady = true
            }
        }

        // Show splash then proceed
        Handler(Looper.getMainLooper()).postDelayed({
            splashContainer.visibility = View.GONE
            if (prefs.getBoolean(KEY_SETUP_COMPLETE, false)) {
                launchWebView()
            } else {
                showWizard()
            }
        }, SPLASH_DURATION)
    }

    private fun bindViews() {
        splashContainer = findViewById(R.id.splashContainer)
        wizardContainer = findViewById(R.id.wizardContainer)
        webView = findViewById(R.id.webView)
        btnSettings = findViewById(R.id.btnSettings)
        step1 = findViewById(R.id.step1)
        step2 = findViewById(R.id.step2)
        step3 = findViewById(R.id.step3)
        stepIndicator = findViewById(R.id.stepIndicator)
        wizardUrl = findViewById(R.id.wizardUrl)
        urlStatus = findViewById(R.id.urlStatus)
        scaleStatusIcon = findViewById(R.id.scaleStatusIcon)
        scaleStatusText = findViewById(R.id.scaleStatusText)
        scaleStatusDetail = findViewById(R.id.scaleStatusDetail)
        scaleQuestionLayout = findViewById(R.id.scaleQuestionLayout)
        step3BottomButtons = findViewById(R.id.step3BottomButtons)

        // Update banner
        updateBanner     = findViewById(R.id.updateBanner)
        tvUpdateMessage  = findViewById(R.id.tvUpdateMessage)
        btnInstallUpdate  = findViewById(R.id.btnInstallUpdate)
        btnDismissUpdate  = findViewById(R.id.btnDismissUpdate)
        downloadProgressBar  = findViewById(R.id.downloadProgressBar)
        downloadProgressText = findViewById(R.id.downloadProgressText)
        bannerProgressBar    = findViewById(R.id.bannerProgressBar)
        btnDismissUpdate.setOnClickListener {
            updateBanner.visibility = View.GONE
            bannerProgressBar.visibility = View.GONE
            pollHandler.removeCallbacksAndMessages(null)
        }
        btnInstallUpdate.setOnClickListener {
            activeInstallBtn = btnInstallUpdate
            triggerApkDownload(pendingApkDownloadUrl)
        }

        // Triple-tap on wizard title is disabled — exit only via the X button in the overlay

        // Step 1
        findViewById<MaterialButton>(R.id.btnGetStarted).setOnClickListener {
            goToStep(2)
        }

        // Step 2
        findViewById<MaterialButton>(R.id.btnTestUrl).setOnClickListener {
            testConnection()
        }
        findViewById<MaterialButton>(R.id.btnStep2Back).setOnClickListener {
            goToStep(1)
        }
        findViewById<MaterialButton>(R.id.btnStep2Next).setOnClickListener {
            val url = wizardUrl.text.toString().trim()
            if (url.isEmpty()) {
                showUrlStatus("Please enter a URL", false)
                return@setOnClickListener
            }
            prefs.edit().putString(KEY_URL, url).apply()
            goToStep(3)
        }

        // Step 3
        findViewById<MaterialButton>(R.id.btnStep3Back).setOnClickListener {
            goToStep(2)
        }
        findViewById<MaterialButton>(R.id.btnFinish).setOnClickListener {
            prefs.edit().putBoolean(KEY_HAS_SCALE, true).apply()
            launchGatewayInBackground()
            finishWizard()
        }
        // "Yes" → reveal gateway status and proceed flow
        findViewById<MaterialButton>(R.id.btnScaleYes).setOnClickListener {
            scaleQuestionLayout.visibility = View.GONE
            val statusCard = findViewById<LinearLayout>(R.id.scaleStatusCard)
            statusCard.visibility = View.VISIBLE
            step3BottomButtons.visibility = View.VISIBLE
            checkGatewayStatus()
        }
        // "No" → save pref and skip to web view
        findViewById<MaterialButton>(R.id.btnScaleNo).setOnClickListener {
            prefs.edit().putBoolean(KEY_HAS_SCALE, false).apply()
            finishWizard()
        }

        // Settings gear — short press opens settings, no kiosk exit via tap
        btnSettings.setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }
        btnSettings.setOnLongClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
            true
        }

        // Pre-fill URL
        val savedUrl = prefs.getString(KEY_URL, "") ?: ""
        if (savedUrl.isNotEmpty()) {
            wizardUrl.setText(savedUrl)
        }
    }

    // ── Runtime Permissions ─────────────────────────────────────────────

    private fun requestAllPermissions() {
        val needed = mutableListOf<String>()
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            needed.add(Manifest.permission.CAMERA)
        }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            needed.add(Manifest.permission.RECORD_AUDIO)
        }
        if (Build.VERSION.SDK_INT >= 33) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_MEDIA_IMAGES) != PackageManager.PERMISSION_GRANTED) {
                needed.add(Manifest.permission.READ_MEDIA_IMAGES)
            }
        } else if (Build.VERSION.SDK_INT <= 32) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED) {
                needed.add(Manifest.permission.READ_EXTERNAL_STORAGE)
            }
        }
        if (needed.isNotEmpty()) {
            ActivityCompat.requestPermissions(this, needed.toTypedArray(), PERMISSION_REQUEST_CODE)
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == PERMISSION_REQUEST_CODE) {
            // Grant pending WebView permission if camera/mic were just granted
            pendingWebPermission?.let { req ->
                val allGranted = grantResults.all { it == PackageManager.PERMISSION_GRANTED }
                if (allGranted) {
                    req.grant(req.resources)
                } else {
                    req.deny()
                }
                pendingWebPermission = null
            }
        }
    }

    // ── Triple-tap to exit ────────────────────────────────────────────────

    private fun handleTripleTap() {
        tapCount++
        tapHandler.removeCallbacks(tapResetRunnable)
        tapHandler.postDelayed(tapResetRunnable, 800)

        when (tapCount) {
            1 -> {} // silent
            2 -> Toast.makeText(this, "Tap once more to exit kiosk", Toast.LENGTH_SHORT).show()
            3 -> {
                tapCount = 0
                disableKioskLock()
                Toast.makeText(this, "Exiting kiosk mode...", Toast.LENGTH_SHORT).show()
                finishAffinity()
            }
        }
    }

    // ── Kiosk Lock (pin app) ──────────────────────────────────────────────

    private fun enableKioskLock() {
        // Screen pinning (task lock) — prevents home/recent buttons
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            startLockTask()
        }
    }

    private fun disableKioskLock() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            try {
                stopLockTask()
            } catch (_: Exception) {}
        }
    }

    // ── Wizard Flow ───────────────────────────────────────────────────────

    private fun showWizard() {
        wizardContainer.visibility = View.VISIBLE
        webView.visibility = View.GONE
        btnSettings.visibility = View.GONE
        goToStep(1)
    }

    private fun goToStep(step: Int) {
        currentStep = step
        step1.visibility = if (step == 1) View.VISIBLE else View.GONE
        step2.visibility = if (step == 2) View.VISIBLE else View.GONE
        step3.visibility = if (step == 3) View.VISIBLE else View.GONE
        updateStepIndicator()

        if (step == 3) {
            // Reset to question state every time step 3 is entered
            scaleQuestionLayout.visibility = View.VISIBLE
            val statusCard = findViewById<LinearLayout>(R.id.scaleStatusCard)
            statusCard.visibility = View.GONE
            step3BottomButtons.visibility = View.GONE
            findViewById<MaterialButton>(R.id.btnSkipScale).visibility = View.GONE
        }
    }

    private fun updateStepIndicator() {
        stepIndicator.removeAllViews()
        for (i in 1..3) {
            val dot = View(this)
            val size = if (i == currentStep) 10 else 8
            val dp = (size * resources.displayMetrics.density).toInt()
            val params = LinearLayout.LayoutParams(dp, dp)
            params.marginStart = (4 * resources.displayMetrics.density).toInt()
            params.marginEnd = (4 * resources.displayMetrics.density).toInt()
            dot.layoutParams = params

            val bg = GradientDrawable()
            bg.shape = GradientDrawable.OVAL
            when {
                i == currentStep -> bg.setColor(0xFF7c3aed.toInt())
                i < currentStep -> bg.setColor(0xFF34d399.toInt())
                else -> bg.setColor(0xFF334155.toInt())
            }
            dot.background = bg
            stepIndicator.addView(dot)
        }
    }

    private fun finishWizard() {
        prefs.edit().putBoolean(KEY_SETUP_COMPLETE, true).apply()
        wizardContainer.visibility = View.GONE
        // Re-init ErrorReporter with the confirmed URL so future errors are reported
        val confirmedUrl = prefs.getString(KEY_URL, "") ?: ""
        ErrorReporter.init(this, confirmedUrl)
        launchWebView()
    }

    fun resetWizard() {
        prefs.edit().putBoolean(KEY_SETUP_COMPLETE, false).apply()
        wizardContainer.visibility = View.VISIBLE
        webView.visibility = View.GONE
        btnSettings.visibility = View.GONE
        goToStep(1)
    }

    // ── Gateway Detection & Launch ────────────────────────────────────────

    private fun isGatewayInstalled(): Boolean {
        return try {
            packageManager.getPackageInfo(GATEWAY_PACKAGE, 0)
            true
        } catch (e: PackageManager.NameNotFoundException) {
            false
        }
    }

    private fun launchGatewayInBackground() {
        if (!prefs.getBoolean(KEY_HAS_SCALE, false)) return
        if (!isGatewayInstalled()) return
        val launchIntent = packageManager.getLaunchIntentForPackage(GATEWAY_PACKAGE) ?: return
        launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        startActivity(launchIntent)
        // Bring kiosk back to foreground after gateway launches
        Handler(Looper.getMainLooper()).postDelayed({
            val am = getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
            am.moveTaskToFront(taskId, ActivityManager.MOVE_TASK_WITH_HOME)
        }, 1500)
    }

    private fun checkGatewayStatus() {
        if (isGatewayInstalled()) {
            scaleStatusIcon.text = "\u2705"
            scaleStatusText.text = getString(R.string.wizard_gateway_installed)
            scaleStatusDetail.text = getString(R.string.wizard_gateway_checking)
            scaleStatusDetail.setTextColor(0xFF94a3b8.toInt())
            findViewById<MaterialButton>(R.id.btnSkipScale).visibility = View.GONE
            findViewById<MaterialButton>(R.id.btnFinish).text = getString(R.string.btn_launch)
            // Check async if a newer version is available
            checkGatewayUpdate()
        } else {
            scaleStatusIcon.text = "\uD83D\uDCE5"
            scaleStatusText.text = getString(R.string.wizard_gateway_not_installed)
            scaleStatusDetail.text = getString(R.string.wizard_gateway_not_installed_detail)
            scaleStatusDetail.setTextColor(0xFFfbbf24.toInt())
            findViewById<MaterialButton>(R.id.btnFinish).text = getString(R.string.btn_launch_no_scale)
            findViewById<MaterialButton>(R.id.btnSkipScale).apply {
                text = getString(R.string.btn_download_gateway)
                setTextColor(0xFFa78bfa.toInt())
                visibility = View.VISIBLE
                setOnClickListener {
                    activeInstallBtn = this
                    triggerApkDownload(GATEWAY_DOWNLOAD_URL)
                }
            }
        }
    }

    /** Fetches the latest GitHub release and, if the gateway has an available update,
     *  shows the update button in the wizard status card. */
    private fun checkGatewayUpdate() {
        val currentVersion = try {
            packageManager.getPackageInfo(GATEWAY_PACKAGE, 0).versionName ?: return
        } catch (_: Exception) { return }

        Thread {
            try {
                val conn = URL(GITHUB_RELEASES_API).openConnection() as java.net.HttpURLConnection
                conn.setRequestProperty("Accept", "application/vnd.github+json")
                conn.connectTimeout = 5000
                conn.readTimeout = 5000
                val json = JSONObject(conn.inputStream.bufferedReader().readText())
                conn.disconnect()

                val latestTag = json.optString("tag_name", "")
                if (latestTag.isEmpty()) { showGatewayUpToDate(); return@Thread }

                val isSemver = latestTag.trimStart('v').matches(Regex("\\d+\\.\\d+.*"))
                val norm = { v: String -> v.trimStart('v') }
                val needsUpdate = !isSemver || norm(latestTag) != norm(currentVersion)

                if (!needsUpdate) { showGatewayUpToDate(); return@Thread }

                // Locate the gateway APK among release assets
                var apkUrl = GATEWAY_DOWNLOAD_URL
                val assets = json.optJSONArray("assets")
                if (assets != null) {
                    for (i in 0 until assets.length()) {
                        val a = assets.getJSONObject(i)
                        val name = a.optString("name", "").lowercase()
                        val url  = a.optString("browser_download_url", "")
                        if ((name.contains("gateway") || name.contains("scale")) && url.isNotEmpty()) {
                            apkUrl = url; break
                        }
                    }
                }
                val finalUrl = apkUrl
                runOnUiThread {
                    scaleStatusIcon.text = "\uD83D\uDD04"
                    scaleStatusText.text = getString(R.string.wizard_gateway_update_available)
                    scaleStatusDetail.text = getString(R.string.wizard_gateway_update_detail)
                    scaleStatusDetail.setTextColor(0xFFfbbf24.toInt())
                    pendingInstallPkg = GATEWAY_PACKAGE
                    pendingApkDownloadUrl = finalUrl
                    findViewById<MaterialButton>(R.id.btnSkipScale).apply {
                        text = getString(R.string.btn_update_gateway)
                        setTextColor(0xFFfbbf24.toInt())
                        visibility = View.VISIBLE
                        setOnClickListener {
                            activeInstallBtn = this
                            triggerApkDownload(finalUrl)
                        }
                    }
                }
            } catch (_: Exception) {
                showGatewayUpToDate()
            }
        }.start()
    }

    private fun showGatewayUpToDate() = runOnUiThread {
        scaleStatusDetail.text = getString(R.string.wizard_gateway_installed_detail)
        scaleStatusDetail.setTextColor(0xFF34d399.toInt())
    }

    /**
     * Central UI updater for the download/install progress.
     * - Updates the wizard status card if it is currently visible (step 3).
     * - Updates the update banner message if it is visible (kiosk self-update).
     * - Always updates the active install button text and enabled state.
     *
     * @param icon      Emoji icon shown in the status card and button
     * @param title     One-line status title (also used as button label)
     * @param detail    Secondary detail line (status card only)
     * @param color     ARGB color for the detail text
     * @param btnEnabled Whether to re-enable the active button after this state
     * @param progress  0-100 to show determinate bar; -1 = indeterminate; -2 = hide bar
     * @param progressText  optional text shown under the bar (e.g. "18.2 MB / 40.5 MB")
     */
    private fun setInstallUI(
        icon: String, title: String, detail: String, color: Int,
        btnEnabled: Boolean = false,
        progress: Int = -2,
        progressText: String = ""
    ) = runOnUiThread {
        // Wizard status card (step 3)
        val statusCard = try { findViewById<LinearLayout>(R.id.scaleStatusCard) } catch (_: Exception) { null }
        if (statusCard?.visibility == View.VISIBLE) {
            scaleStatusIcon.text = icon
            scaleStatusText.text = title
            scaleStatusDetail.text = detail
            scaleStatusDetail.setTextColor(color)
            when {
                progress == -2 -> {
                    downloadProgressBar.visibility = View.GONE
                    downloadProgressText.visibility = View.GONE
                }
                progress == -1 -> {
                    downloadProgressBar.isIndeterminate = true
                    downloadProgressBar.visibility = View.VISIBLE
                    downloadProgressText.text = progressText
                    downloadProgressText.visibility = if (progressText.isEmpty()) View.GONE else View.VISIBLE
                }
                else -> {
                    downloadProgressBar.isIndeterminate = false
                    downloadProgressBar.progress = progress
                    downloadProgressBar.visibility = View.VISIBLE
                    downloadProgressText.text = progressText
                    downloadProgressText.visibility = if (progressText.isEmpty()) View.GONE else View.VISIBLE
                }
            }
        }
        // Update banner (kiosk / gateway auto-update outside wizard)
        if (updateBanner.visibility == View.VISIBLE) {
            tvUpdateMessage.text = "$icon  $title"
            if (detail.isNotEmpty()) tvUpdateMessage.text = "${tvUpdateMessage.text}\n$detail"
            when {
                progress == -2 -> bannerProgressBar.visibility = View.GONE
                progress == -1 -> {
                    bannerProgressBar.isIndeterminate = true
                    bannerProgressBar.visibility = View.VISIBLE
                }
                else -> {
                    bannerProgressBar.isIndeterminate = false
                    bannerProgressBar.progress = progress
                    bannerProgressBar.visibility = View.VISIBLE
                }
            }
        }
        // Button state
        val btn = activeInstallBtn
        if (btn != null) {
            btn.isEnabled = btnEnabled
            btn.text = "$icon  $title"
        }
    }

    /**
     * Polls DownloadManager every 500 ms to report actual byte-level progress
     * in the status card and banner. Stops automatically when download is no
     * longer RUNNING or PENDING.
     */
    private fun startDownloadProgressPoll(downloadId: Long) {
        activeDownloadId = downloadId
        pollHandler.removeCallbacksAndMessages(null)
        fun tick() {
            if (activeDownloadId != downloadId) return   // superseded download
            val dm = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
            val c  = dm.query(DownloadManager.Query().setFilterById(downloadId))
            if (!c.moveToFirst()) { c.close(); return }
            val status = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS))
            if (status == DownloadManager.STATUS_RUNNING ||
                status == DownloadManager.STATUS_PENDING) {
                val dl  = c.getLong(c.getColumnIndexOrThrow(DownloadManager.COLUMN_BYTES_DOWNLOADED_SO_FAR))
                val tot = c.getLong(c.getColumnIndexOrThrow(DownloadManager.COLUMN_TOTAL_SIZE_BYTES))
                c.close()
                val pct = if (tot > 0) (dl * 100 / tot).toInt() else 0
                val dlMb  = dl  / 1_048_576f
                val totMb = tot / 1_048_576f
                val txt = if (tot > 0) "%.1f MB / %.1f MB".format(dlMb, totMb) else ""
                setInstallUI(
                    "\u23F3",
                    getString(R.string.install_downloading) + if (tot > 0) " ($pct%)" else "",
                    txt,
                    0xFF94a3b8.toInt(),
                    btnEnabled = false,
                    progress = pct,
                    progressText = txt
                )
                pollHandler.postDelayed({ tick() }, 500)
            } else {
                c.close()  // terminal state — BroadcastReceiver will handle success/failure
            }
        }
        pollHandler.post { tick() }
    }

    // ── Connection Test ───────────────────────────────────────────────────

    private fun testConnection() {
        val url = wizardUrl.text.toString().trim()
        if (url.isEmpty()) {
            showUrlStatus("Please enter a URL first", false)
            return
        }
        showUrlStatus("Testing connection...", null)

        Thread {
            try {
                val conn = URL(url).openConnection()

                if (conn is HttpsURLConnection) {
                    val trustAll = arrayOf<TrustManager>(object : X509TrustManager {
                        override fun checkClientTrusted(chain: Array<java.security.cert.X509Certificate>?, authType: String?) {}
                        override fun checkServerTrusted(chain: Array<java.security.cert.X509Certificate>?, authType: String?) {}
                        override fun getAcceptedIssuers(): Array<java.security.cert.X509Certificate> = arrayOf()
                    })
                    val sc = SSLContext.getInstance("TLS")
                    sc.init(null, trustAll, java.security.SecureRandom())
                    conn.sslSocketFactory = sc.socketFactory
                    conn.hostnameVerifier = javax.net.ssl.HostnameVerifier { _, _ -> true }
                }

                conn.connectTimeout = 5000
                conn.readTimeout = 5000
                if (conn is java.net.HttpURLConnection) {
                    conn.requestMethod = "GET"
                    val code = conn.responseCode
                    conn.disconnect()
                    runOnUiThread {
                        if (code in 200..399) {
                            showUrlStatus("✓ Connected successfully!", true)
                        } else {
                            showUrlStatus("⚠ Server responded with code $code", false)
                        }
                    }
                }
            } catch (e: Exception) {
                runOnUiThread {
                    showUrlStatus("✗ Cannot reach server: ${e.message}", false)
                }
            }
        }.start()
    }

    private fun showUrlStatus(text: String, success: Boolean?) {
        urlStatus.visibility = View.VISIBLE
        urlStatus.text = text
        urlStatus.setTextColor(
            when (success) {
                true -> 0xFF34d399.toInt()
                false -> 0xFFf87171.toInt()
                null -> 0xFF94a3b8.toInt()
            }
        )
    }

    // ── WebView ───────────────────────────────────────────────────────────

    @SuppressLint("SetJavaScriptEnabled")
    private fun launchWebView() {
        webView.visibility = View.VISIBLE
        btnSettings.visibility = View.VISIBLE

        val settings = webView.settings
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true
        settings.mediaPlaybackRequiresUserGesture = false
        settings.allowFileAccess = true
        settings.mixedContentMode = android.webkit.WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
        settings.cacheMode = android.webkit.WebSettings.LOAD_NO_CACHE

        webView.webViewClient = object : WebViewClient() {
            override fun onReceivedSslError(
                view: WebView?, handler: SslErrorHandler?, error: SslError?
            ) {
                handler?.proceed()
            }

            override fun onReceivedError(
                view: WebView?, request: WebResourceRequest?,
                error: WebResourceError?
            ) {
                val errorDesc = error?.description?.toString() ?: "unknown"
                val errorCode = error?.errorCode ?: -1
                val url = request?.url?.toString() ?: ""
                if (request?.isForMainFrame == true) {
                    ErrorReporter.reportMessage(
                        type    = "webview-load-error",
                        message = "WebView failed to load main frame: $errorDesc (code $errorCode)",
                        extra   = mapOf("url" to url, "errorCode" to errorCode)
                    )
                    view?.loadData(errorPageHtml(), "text/html", "UTF-8")
                }
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                // Inject X (exit) and ↻ (refresh) buttons into the page header
                injectKioskOverlay()
                // Check for updates periodically
                checkForUpdates()
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onPermissionRequest(request: PermissionRequest?) {
                request ?: return
                runOnUiThread {
                    val needed = mutableListOf<String>()
                    for (res in request.resources) {
                        when (res) {
                            PermissionRequest.RESOURCE_VIDEO_CAPTURE -> {
                                if (ContextCompat.checkSelfPermission(this@KioskActivity, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
                                    needed.add(Manifest.permission.CAMERA)
                                }
                            }
                            PermissionRequest.RESOURCE_AUDIO_CAPTURE -> {
                                if (ContextCompat.checkSelfPermission(this@KioskActivity, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
                                    needed.add(Manifest.permission.RECORD_AUDIO)
                                }
                            }
                        }
                    }
                    if (needed.isEmpty()) {
                        request.grant(request.resources)
                    } else {
                        pendingWebPermission = request
                        ActivityCompat.requestPermissions(this@KioskActivity, needed.toTypedArray(), PERMISSION_REQUEST_CODE)
                    }
                }
            }
            override fun onConsoleMessage(msg: ConsoleMessage?): Boolean {
                // Forward JS errors and warnings to the error reporter
                if (msg != null && msg.messageLevel() == ConsoleMessage.MessageLevel.ERROR) {
                    ErrorReporter.reportMessage(
                        type    = "webview-js-error",
                        message = msg.message(),
                        extra   = mapOf(
                            "source_id" to msg.sourceId(),
                            "line"      to msg.lineNumber()
                        )
                    )
                }
                return true
            }
            override fun onShowFileChooser(
                wv: WebView?,
                callback: ValueCallback<Array<Uri>>?,
                params: FileChooserParams?
            ): Boolean {
                fileChooserCallback?.onReceiveValue(null)
                fileChooserCallback = callback
                val intent = params?.createIntent()
                if (intent != null) {
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST)
                }
                return true
            }
        }

        // Add JS interface ONCE before loading
        webView.addJavascriptInterface(object {
            @JavascriptInterface
            fun exit() {
                runOnUiThread {
                    disableKioskLock()
                    Toast.makeText(this@KioskActivity, "Exiting kiosk mode...", Toast.LENGTH_SHORT).show()
                    finishAffinity()
                }
            }
            @JavascriptInterface
            fun hardReload() {
                runOnUiThread {
                    webView.clearCache(true)
                    webView.reload()
                }
            }
            /**
             * Speak [text] via Android native TTS.
             * Called by app.js when running inside the kiosk WebView so that
             * speech synthesis works even without Web Speech API offline voices.
             * [rate] and [pitch] are floats (default 1.0).
             */
            @JavascriptInterface
            fun speak(text: String, rate: Float, pitch: Float) {
                val engine = tts ?: return
                if (!ttsReady) return
                engine.setSpeechRate(rate.coerceIn(0.1f, 4f))
                engine.setPitch(pitch.coerceIn(0.1f, 4f))
                engine.speak(text, TextToSpeech.QUEUE_FLUSH, null, "kiosk_tts")
            }
            /** Cancel any ongoing speech. */
            @JavascriptInterface
            fun stopSpeech() {
                tts?.stop()
            }
            /** Returns "true" when the TTS engine is ready. */
            @JavascriptInterface
            fun isTtsReady(): String = if (ttsReady) "true" else "false"
        }, "_kioskBridge")

        val url = prefs.getString(KEY_URL, "http://evershelf.local") ?: "http://evershelf.local"
        webView.loadUrl(url)

        // Launch gateway in background
        launchGatewayInBackground()

        // Keep screen on
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
    }

    // ── Inject kiosk buttons in header (left of title) ──────────────────

    private fun injectKioskOverlay() {
        // Use a position:fixed overlay so injection never depends on SPA DOM readiness.
        val js = """
        (function() {
            if (document.getElementById('_kiosk_overlay')) return;

            var wrap = document.createElement('div');
            wrap.id = '_kiosk_overlay';
            wrap.style.cssText = 'position:fixed;top:8px;left:8px;z-index:2147483647;display:flex;gap:6px;align-items:center;pointer-events:auto;';

            // Exit button
            var exitBtn = document.createElement('button');
            exitBtn.id = '_kiosk_exit_btn';
            exitBtn.textContent = '\u2715';
            exitBtn.title = 'Esci dal kiosk';
            exitBtn.style.cssText = 'background:rgba(0,0,0,0.45);border:1.5px solid rgba(255,255,255,0.5);color:#fff;width:34px;height:34px;border-radius:50%;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;touch-action:manipulation;';
            exitBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (confirm('Uscire dalla modalit\u00e0 kiosk?')) {
                    if (typeof _kioskBridge !== 'undefined') _kioskBridge.exit();
                }
            });

            // Refresh button
            var refBtn = document.createElement('button');
            refBtn.id = '_kiosk_refresh_btn';
            refBtn.textContent = '\u21bb';
            refBtn.title = 'Aggiorna pagina';
            refBtn.style.cssText = 'background:rgba(0,0,0,0.45);border:1.5px solid rgba(255,255,255,0.5);color:#fff;width:34px;height:34px;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;touch-action:manipulation;';
            refBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (typeof _kioskBridge !== 'undefined') _kioskBridge.hardReload();
                else location.reload(true);
            });

            wrap.appendChild(exitBtn);
            wrap.appendChild(refBtn);
            document.documentElement.appendChild(wrap);
        })();
        """.trimIndent()
        webView.evaluateJavascript(js, null)
    }

    // ── Update Check ──────────────────────────────────────────────────────

    private fun checkForUpdates() {
        val lastCheck = prefs.getLong("last_update_check", 0)
        val now = System.currentTimeMillis()
        // Check at most once every 6 hours
        if (now - lastCheck < 6 * 60 * 60 * 1000) return
        prefs.edit().putLong("last_update_check", now).apply()

        Thread {
            try {
                val conn = URL(GITHUB_RELEASES_API).openConnection() as java.net.HttpURLConnection
                conn.setRequestProperty("Accept", "application/vnd.github+json")
                conn.connectTimeout = 5000
                conn.readTimeout = 5000
                val body = conn.inputStream.bufferedReader().readText()
                conn.disconnect()
                val json = JSONObject(body)
                val latestTag = json.optString("tag_name", "")
                if (latestTag.isEmpty()) return@Thread

                val currentKiosk = try {
                    packageManager.getPackageInfo(packageName, 0).versionName ?: ""
                } catch (_: Exception) { "" }
                val currentGateway = try {
                    packageManager.getPackageInfo(GATEWAY_PACKAGE, 0).versionName ?: ""
                } catch (_: Exception) { null }

                // Normalise: strip leading 'v' for comparison
                val norm = { v: String -> v.trimStart('v') }
                // If tag is not semver-like (e.g. "latest") we can't compare — treat as "needs update"
                val isSemver = latestTag.trimStart('v').matches(Regex("\\d+\\.\\d+.*"))

                // Find APK download URLs in release assets
                val assets = json.optJSONArray("assets")
                var kioskApkUrl = ""     // only set if the release actually contains the APK
                var gatewayApkUrl = ""
                if (assets != null) {
                    for (i in 0 until assets.length()) {
                        val a    = assets.getJSONObject(i)
                        val name = a.optString("name", "").lowercase()
                        val url  = a.optString("browser_download_url", "")
                        if (name.contains("kiosk") && url.isNotEmpty()) kioskApkUrl = url
                        if ((name.contains("gateway") || name.contains("scale")) && url.isNotEmpty()) gatewayApkUrl = url
                    }
                }

                // Kiosk needs update: APK is in release AND (non-semver tag OR version mismatch)
                val kioskHasApk = kioskApkUrl.isNotEmpty()
                val kioskNeedsUpdate = kioskHasApk && currentKiosk.isNotEmpty() &&
                    (!isSemver || norm(latestTag) != norm(currentKiosk))

                // Gateway needs update: installed AND APK in release AND (non-semver OR mismatch)
                val gatewayHasApk = gatewayApkUrl.isNotEmpty()
                val gatewayNeedsUpdate = currentGateway != null && gatewayHasApk &&
                    (!isSemver || norm(latestTag) != norm(currentGateway))

                if (!kioskNeedsUpdate && !gatewayNeedsUpdate) return@Thread

                // Build message and choose primary download (kiosk takes precedence)
                val lines = mutableListOf<String>()
                var primaryApkUrl = ""
                if (kioskNeedsUpdate) {
                    val label = if (isSemver) "$currentKiosk → $latestTag" else latestTag
                    lines += "🔄 Kiosk $label"
                    primaryApkUrl = kioskApkUrl
                }
                if (gatewayNeedsUpdate) {
                    val label = if (isSemver) "$currentGateway → $latestTag" else latestTag
                    lines += "🔄 Scale Gateway $label"
                    if (primaryApkUrl.isEmpty()) primaryApkUrl = gatewayApkUrl
                }
                val message = lines.joinToString("  •  ")

                runOnUiThread { showNativeUpdateBanner(message, primaryApkUrl) }
            } catch (_: Exception) { }
        }.start()
    }

    /**
     * Shows a native Android banner at the TOP of the screen (above the WebView).
     * Includes a prominent "Scarica" button that downloads and installs the APK.
     */
    private fun showNativeUpdateBanner(message: String, apkDownloadUrl: String) {
        pendingApkDownloadUrl = apkDownloadUrl
        tvUpdateMessage.text = "⬆️ Aggiornamento disponibile:  $message"
        updateBanner.visibility = View.VISIBLE
        // Auto-hide after 30 s (user can dismiss manually)
        updateBanner.postDelayed({ updateBanner.visibility = View.GONE }, 30_000)
    }

    /**
     * Downloads the APK via DownloadManager and opens the installer when done.
     * Requires INTERNET + REQUEST_INSTALL_PACKAGES permissions.
     * All progress is reflected in the active UI (status card or banner) — no Toasts.
     */
    private fun triggerApkDownload(apkUrl: String) {
        if (apkUrl.isEmpty()) return
        // Always keep this up-to-date so installApk() can derive the target package from the URL.
        pendingApkDownloadUrl = apkUrl
        try {
            // On Android 8+ check the "install unknown apps" source permission
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O &&
                !packageManager.canRequestPackageInstalls()) {
                // pendingApkDownloadUrl already set above
                setInstallUI(
                    "\uD83D\uDD12",
                    getString(R.string.install_perm_detail),
                    getString(R.string.install_perm_detail),
                    0xFFfbbf24.toInt(),
                    btnEnabled = false
                )
                @Suppress("DEPRECATION")
                startActivityForResult(
                    Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                        Uri.parse("package:$packageName")),
                    INSTALL_PERM_REQUEST
                )
                return
            }

            // Show "downloading" state immediately
            setInstallUI(
                "\u23F3",
                getString(R.string.install_downloading),
                getString(R.string.install_downloading_detail),
                0xFF94a3b8.toInt(),
                btnEnabled = false
            )

            // Download to app-private external dir — no storage permission needed
            val destDir  = getExternalFilesDir(null) ?: filesDir
            val destFile = java.io.File(destDir, "evershelf-update.apk")

            val dm  = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
            val req = DownloadManager.Request(Uri.parse(apkUrl)).apply {
                setTitle("EverShelf — Aggiornamento")
                setDescription(getString(R.string.install_downloading))
                setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                setDestinationUri(Uri.fromFile(destFile))
                setMimeType("application/vnd.android.package-archive")
            }
            val downloadId = dm.enqueue(req)
            startDownloadProgressPoll(downloadId)

            val receiver = object : BroadcastReceiver() {
                override fun onReceive(ctx: Context?, intent: Intent?) {
                    val id = intent?.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1)
                    if (id != downloadId) return
                    unregisterReceiver(this)
                    // Verify the download succeeded before trying to install
                    val q  = DownloadManager.Query().setFilterById(downloadId)
                    val c  = (getSystemService(DOWNLOAD_SERVICE) as DownloadManager).query(q)
                    var ok = false
                    if (c.moveToFirst()) {
                        val status = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS))
                        ok = (status == DownloadManager.STATUS_SUCCESSFUL)
                    }
                    c.close()
                    if (ok) {
                        pollHandler.removeCallbacksAndMessages(null)
                        activeDownloadId = -1
                        setInstallUI(
                            "\u23F3",
                            getString(R.string.install_installing),
                            getString(R.string.install_installing),
                            0xFF94a3b8.toInt(),
                            btnEnabled = false,
                            progress = -1
                        )
                        installApk(destFile)
                    } else {
                        pollHandler.removeCallbacksAndMessages(null)
                        activeDownloadId = -1
                        setInstallUI(
                            "\u274C",
                            getString(R.string.install_error_download),
                            getString(R.string.install_error_download_detail),
                            0xFFf87171.toInt(),
                            btnEnabled = true,
                            progress = -2
                        )
                        runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
                        ErrorReporter.reportMessage("install_download_failed",
                            "DownloadManager returned failure for URL: $apkUrl")
                    }
                }
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                // RECEIVER_EXPORTED required: ACTION_DOWNLOAD_COMPLETE is sent by the system DownloadManager
                // (an external process), so NOT_EXPORTED would silently block the broadcast on API 33+.
                registerReceiver(receiver, IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE), RECEIVER_EXPORTED)
            } else {
                @Suppress("UnspecifiedRegisterReceiverFlag")
                registerReceiver(receiver, IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE))
            }
        } catch (e: Exception) {
            setInstallUI(
                "\u274C",
                getString(R.string.install_error_download),
                e.message ?: "",
                0xFFf87171.toInt(),
                btnEnabled = true
            )
            runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
        }
    }

    private fun installApk(file: java.io.File) {
        if (!file.exists() || file.length() == 0L) {
            setInstallUI(
                "\u274C",
                getString(R.string.install_error_download),
                "File APK non trovato sul dispositivo.",
                0xFFf87171.toInt(),
                btnEnabled = true
            )
            runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
            return
        }
        // Derive the target package from the download URL (not the filename, which is always
        // 'evershelf-update.apk'). The URL contains 'gateway' or 'scale' when installing the
        // scale gateway; anything else is a kiosk self-update.
        val targetPkg = when {
            pendingApkDownloadUrl.contains("gateway", ignoreCase = true) ||
            pendingApkDownloadUrl.contains("scale",   ignoreCase = true) -> GATEWAY_PACKAGE
            else -> packageName
        }
        installWithPackageInstaller(file, targetPkg)
    }

    /** Use PackageInstaller (API 21+) for reliable install-over-existing support. */
    private fun installWithPackageInstaller(file: java.io.File, targetPkg: String) {
        try {
            val pi = packageManager.packageInstaller
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
                // Register a BroadcastReceiver for the install result
                val action = "it.dadaloop.evershelf.kiosk.INSTALL_RESULT_$sessionId"
                val resultReceiver = object : BroadcastReceiver() {
                    override fun onReceive(ctx: Context?, intent: Intent?) {
                        unregisterReceiver(this)
                        val status = intent?.getIntExtra(
                            android.content.pm.PackageInstaller.EXTRA_STATUS,
                            android.content.pm.PackageInstaller.STATUS_FAILURE
                        ) ?: android.content.pm.PackageInstaller.STATUS_FAILURE
                        when (status) {
                            android.content.pm.PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                                // Android needs user confirmation — use startActivityForResult so we
                                // get notified if the system installer fails (e.g. signature conflict)
                                @Suppress("DEPRECATION")
                                val confirmIntent = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU)
                                    intent?.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java)
                                else intent?.getParcelableExtra(Intent.EXTRA_INTENT)
                                if (confirmIntent != null) {
                                    pendingInstallFile = file
                                    pendingInstallPkg  = targetPkg
                                    setInstallUI(
                                        "\u23F3",
                                        getString(R.string.install_installing),
                                        getString(R.string.install_confirm_detail),
                                        0xFF94a3b8.toInt(),
                                        btnEnabled = false
                                    )
                                    startActivityForResult(confirmIntent, INSTALL_CONFIRM_REQUEST)
                                }
                            }
                            android.content.pm.PackageInstaller.STATUS_SUCCESS -> {
                                setInstallUI(
                                    "\u2705",
                                    getString(R.string.install_success),
                                    getString(R.string.install_success_detail),
                                    0xFF34d399.toInt(),
                                    btnEnabled = false,
                                    progress = -2
                                )
                                // Re-check gateway status after 3 s so the wizard reflects reality
                                Handler(Looper.getMainLooper()).postDelayed({
                                    val card = try { findViewById<LinearLayout>(R.id.scaleStatusCard) } catch (_: Exception) { null }
                                    if (card?.visibility == View.VISIBLE) checkGatewayStatus()
                                    updateBanner.visibility = View.GONE
                                    bannerProgressBar.visibility = View.GONE
                                }, 3000)
                            }
                            android.content.pm.PackageInstaller.STATUS_FAILURE_INCOMPATIBLE,
                            android.content.pm.PackageInstaller.STATUS_FAILURE_CONFLICT -> {
                                // Signature mismatch: offer to uninstall; on return auto-retry install
                                runOnUiThread {
                                    pendingInstallFile = file
                                    pendingInstallPkg  = targetPkg
                                    androidx.appcompat.app.AlertDialog.Builder(this@KioskActivity)
                                        .setTitle("⚠️ Conflitto firma APK")
                                        .setMessage("L'app installata usa una firma diversa.\n\nDisinstalla la versione precedente: al termine l'installazione riparte automaticamente.")
                                        .setPositiveButton("Disinstalla") { _, _ ->
                                            startActivityForResult(
                                                Intent(Intent.ACTION_DELETE, android.net.Uri.parse("package:$targetPkg")),
                                                UNINSTALL_REQUEST
                                            )
                                        }
                                        .setNegativeButton("Annulla", null)
                                        .show()
                                }
                            }
                            else -> {
                                val msg = intent?.getStringExtra(
                                    android.content.pm.PackageInstaller.EXTRA_STATUS_MESSAGE
                                ) ?: "status=$status"
                                setInstallUI(
                                    "\u274C",
                                    getString(R.string.install_error_download),
                                    msg,
                                    0xFFf87171.toInt(),
                                    btnEnabled = true,
                                    progress = -2
                                )
                                runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
                                ErrorReporter.reportMessage("install_failure",
                                    "PackageInstaller status=$status msg=$msg pkg=$targetPkg")
                            }
                        }
                    }
                }
                val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU)
                    RECEIVER_NOT_EXPORTED else 0
                registerReceiver(resultReceiver, IntentFilter(action), flags)
                val pi2 = PendingIntent.getBroadcast(
                    this, sessionId,
                    Intent(action).setPackage(packageName),
                    PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
                )
                session.commit(pi2.intentSender)
            }
            // "Installazione in corso…" is already set by the download-complete handler.
            // If called from onActivityResult (retry after uninstall), set it now.
            setInstallUI(
                "\u23F3",
                getString(R.string.install_installing),
                getString(R.string.install_installing),
                0xFF94a3b8.toInt(),
                btnEnabled = false,
                progress = -1
            )
        } catch (e: Exception) {
            setInstallUI(
                "\u274C",
                getString(R.string.install_error_download),
                e.message ?: "",
                0xFFf87171.toInt(),
                btnEnabled = true,
                progress = -2
            )
            runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
            ErrorReporter.reportMessage("install_packager_exception",
                "installWithPackageInstaller exception for $targetPkg: ${e.message}")
        }
    }

    // ── Error Page ────────────────────────────────────────────────────────

    private fun errorPageHtml(): String {
        val url = prefs.getString(KEY_URL, "") ?: ""
        return """
        <html>
        <head><meta name='viewport' content='width=device-width,initial-scale=1'></head>
        <body style='background:#0f172a;color:#f1f5f9;font-family:sans-serif;
                      display:flex;flex-direction:column;align-items:center;
                      justify-content:center;height:100vh;margin:0;padding:24px;
                      text-align:center;'>
            <div style='font-size:48px;margin-bottom:16px;'>⚠️</div>
            <h2 style='margin:0 0 8px 0;'>Cannot reach EverShelf</h2>
            <p style='color:#94a3b8;margin:0 0 8px 0;'>$url</p>
            <p style='color:#64748b;font-size:14px;margin:0 0 32px 0;'>
                Check that the server is running and the URL is correct.
            </p>
            <button onclick='location.reload()'
                    style='background:#7c3aed;color:#fff;border:none;padding:14px 32px;
                           border-radius:12px;font-size:16px;cursor:pointer;'>
                Retry
            </button>
        </body>
        </html>
        """.trimIndent()
    }

    // ── Immersive Mode ────────────────────────────────────────────────────

    private fun enterImmersiveMode() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.insetsController?.let {
                it.hide(WindowInsets.Type.systemBars())
                it.systemBarsBehavior = WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
        } else {
            @Suppress("DEPRECATION")
            window.decorView.systemUiVisibility = (
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                    or View.SYSTEM_UI_FLAG_FULLSCREEN
                    or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                    or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                    or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                    or View.SYSTEM_UI_FLAG_LAYOUT_STABLE
            )
        }
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────

    override fun onResume() {
        super.onResume()
        enterImmersiveMode()
        if (prefs.getBoolean(KEY_SETUP_COMPLETE, false) && webView.visibility == View.VISIBLE) {
            val url = prefs.getString(KEY_URL, "") ?: ""
            if (url.isNotEmpty() && webView.url != url) {
                webView.loadUrl(url)
            }
        }
        if (!prefs.getBoolean(KEY_SETUP_COMPLETE, false) &&
            wizardContainer.visibility != View.VISIBLE &&
            splashContainer.visibility != View.VISIBLE) {
            showWizard()
        }
        if (currentStep == 3 && wizardContainer.visibility == View.VISIBLE) {
            val statusCard = findViewById<LinearLayout>(R.id.scaleStatusCard)
            // Only re-check if the user has already answered "Yes" (status card visible)
            if (statusCard.visibility == View.VISIBLE) checkGatewayStatus()
        }
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == FILE_CHOOSER_REQUEST) {
            val result = if (resultCode == RESULT_OK && data != null) {
                WebChromeClient.FileChooserParams.parseResult(resultCode, data)
            } else null
            fileChooserCallback?.onReceiveValue(result)
            fileChooserCallback = null
        }
        // Returned from ACTION_MANAGE_UNKNOWN_APP_SOURCES — retry the download
        // regardless of resultCode (the system always returns RESULT_CANCELED here).
        if (requestCode == INSTALL_PERM_REQUEST) {
            val url = pendingApkDownloadUrl
            if (url.isNotEmpty()) triggerApkDownload(url)
        }
        // System installer returned: OK = install succeeded.
        if (requestCode == INSTALL_CONFIRM_REQUEST && resultCode == RESULT_OK) {
            setInstallUI(
                "\u2705",
                getString(R.string.install_success),
                getString(R.string.install_success_detail),
                0xFF34d399.toInt(),
                btnEnabled = false,
                progress = -2
            )
            Handler(Looper.getMainLooper()).postDelayed({
                val card = try { findViewById<LinearLayout>(R.id.scaleStatusCard) } catch (_: Exception) { null }
                if (card?.visibility == View.VISIBLE) checkGatewayStatus()
                updateBanner.visibility = View.GONE
                bannerProgressBar.visibility = View.GONE
            }, 3000)
        }
        // Not OK = install failed (possibly signature conflict).
        // Show a dialog offering to uninstall the old version so the user can retry.
        if (requestCode == INSTALL_CONFIRM_REQUEST && resultCode != RESULT_OK) {
            val f   = pendingInstallFile
            val pkg = pendingInstallPkg
            if (f != null && f.exists() && pkg.isNotEmpty()) {
                runOnUiThread {
                    androidx.appcompat.app.AlertDialog.Builder(this)
                        .setTitle("⚠️ Installazione non riuscita")
                        .setMessage("Se hai visto un errore di conflitto firma, devi disinstallare la versione precedente.\n\nDisinstalla ora? L'installazione ripartirà automaticamente.")
                        .setPositiveButton("Disinstalla") { _, _ ->
                            startActivityForResult(
                                Intent(Intent.ACTION_DELETE, android.net.Uri.parse("package:$pkg")),
                                UNINSTALL_REQUEST
                            )
                        }
                        .setNegativeButton("Annulla", null)
                        .show()
                }
            }
        }
        // Returned from uninstall screen — auto-retry the install with the saved APK file.
        if (requestCode == UNINSTALL_REQUEST) {
            val f   = pendingInstallFile
            val pkg = pendingInstallPkg
            if (f != null && f.exists() && pkg.isNotEmpty()) {
                installWithPackageInstaller(f, pkg)
            }
        }
    }

    override fun onDestroy() {
        tts?.stop()
        tts?.shutdown()
        tts = null
        super.onDestroy()
    }

    override fun onBackPressed() {
        if (webView.visibility == View.VISIBLE && webView.canGoBack()) {
            webView.goBack()
        }
        // Block back button in kiosk mode
    }
}
