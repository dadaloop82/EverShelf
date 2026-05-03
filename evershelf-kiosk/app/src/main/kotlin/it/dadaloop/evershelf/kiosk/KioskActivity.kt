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
import android.content.pm.PackageManager
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.net.http.SslError
import android.os.Build
import android.os.Bundle
import android.os.Environment
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
    // Update banner (native, shown at the top over the WebView)
    private lateinit var updateBanner: LinearLayout
    private lateinit var tvUpdateMessage: TextView
    private lateinit var btnInstallUpdate: MaterialButton
    private lateinit var btnDismissUpdate: MaterialButton
    private var pendingApkDownloadUrl: String = ""

    // Triple-tap to exit
    private var tapCount = 0
    private val tapHandler = Handler(Looper.getMainLooper())
    private val tapResetRunnable = Runnable { tapCount = 0 }

    // File chooser
    private var fileChooserCallback: ValueCallback<Array<Uri>>? = null

    // Pending WebView permission request (waiting for runtime grant)
    private var pendingWebPermission: PermissionRequest? = null

    companion object {
        private const val FILE_CHOOSER_REQUEST = 1002
        private const val PERMISSION_REQUEST_CODE = 1003
        private const val PREFS_NAME = "evershelf_kiosk"
        private const val KEY_URL = "evershelf_url"
        private const val KEY_SETUP_COMPLETE = "setup_complete"
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

        // Update banner
        updateBanner    = findViewById(R.id.updateBanner)
        tvUpdateMessage = findViewById(R.id.tvUpdateMessage)
        btnInstallUpdate = findViewById(R.id.btnInstallUpdate)
        btnDismissUpdate = findViewById(R.id.btnDismissUpdate)
        btnDismissUpdate.setOnClickListener { updateBanner.visibility = View.GONE }
        btnInstallUpdate.setOnClickListener { triggerApkDownload(pendingApkDownloadUrl) }

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
            launchGatewayInBackground()
            finishWizard()
        }
        findViewById<MaterialButton>(R.id.btnSkipScale).setOnClickListener {
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
            checkGatewayStatus()
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
            scaleStatusIcon.text = "✅"
            scaleStatusText.text = "Scale Gateway is installed"
            scaleStatusDetail.text = "It will be launched in the background when you proceed"
            scaleStatusDetail.setTextColor(0xFF34d399.toInt())
            findViewById<MaterialButton>(R.id.btnSkipScale).visibility = View.GONE
            findViewById<MaterialButton>(R.id.btnFinish).text = "🚀  Launch EverShelf"
        } else {
            scaleStatusIcon.text = "📥"
            scaleStatusText.text = "Scale Gateway not installed"
            scaleStatusDetail.text = "Install the Scale Gateway app to use a Bluetooth scale"
            scaleStatusDetail.setTextColor(0xFFfbbf24.toInt())

            findViewById<MaterialButton>(R.id.btnFinish).text = "🚀  Launch without scale"

            findViewById<MaterialButton>(R.id.btnSkipScale).apply {
                text = "📥  Download Scale Gateway"
                setTextColor(0xFF7c3aed.toInt())
                visibility = View.VISIBLE
                setOnClickListener {
                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(GATEWAY_DOWNLOAD_URL))
                    startActivity(intent)
                }
            }
        }
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

                val kioskNeedsUpdate = latestTag.isNotEmpty() && currentKiosk.isNotEmpty() &&
                    norm(latestTag) != norm(currentKiosk)
                val gatewayNeedsUpdate = currentGateway != null && latestTag.isNotEmpty() &&
                    norm(latestTag) != norm(currentGateway)

                if (!kioskNeedsUpdate && !gatewayNeedsUpdate) return@Thread

                // Find APK download URLs in release assets
                val assets = json.optJSONArray("assets")
                var kioskApkUrl = KIOSK_DOWNLOAD_URL
                var gatewayApkUrl = GATEWAY_DOWNLOAD_URL
                if (assets != null) {
                    for (i in 0 until assets.length()) {
                        val a = assets.getJSONObject(i)
                        val name = a.optString("name", "").lowercase()
                        val url  = a.optString("browser_download_url", "")
                        if (name.contains("kiosk") && url.isNotEmpty()) kioskApkUrl = url
                        if ((name.contains("gateway") || name.contains("scale")) && url.isNotEmpty()) gatewayApkUrl = url
                    }
                }

                // Build message and choose primary download (kiosk takes precedence)
                val lines = mutableListOf<String>()
                var primaryApkUrl = ""
                if (kioskNeedsUpdate) {
                    lines += "🔄 Kiosk $currentKiosk → $latestTag"
                    primaryApkUrl = kioskApkUrl
                }
                if (gatewayNeedsUpdate) {
                    lines += "🔄 Scale Gateway $currentGateway → $latestTag"
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
     */
    private fun triggerApkDownload(apkUrl: String) {
        if (apkUrl.isEmpty()) return
        try {
            // On Android 8+ we need to check "install unknown apps" permission
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O &&
                !packageManager.canRequestPackageInstalls()) {
                val intent = Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                    Uri.parse("package:$packageName"))
                startActivity(intent)
                Toast.makeText(this, "Abilita 'Installa app sconosciute', poi ripremi Scarica", Toast.LENGTH_LONG).show()
                return
            }

            val dm = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
            val req = DownloadManager.Request(Uri.parse(apkUrl)).apply {
                setTitle("EverShelf — Aggiornamento")
                setDescription("Scaricamento aggiornamento in corso…")
                setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, "evershelf-update.apk")
                setMimeType("application/vnd.android.package-archive")
            }
            val downloadId = dm.enqueue(req)
            Toast.makeText(this, "Download avviato…", Toast.LENGTH_SHORT).show()

            // Listen for completion
            val receiver = object : BroadcastReceiver() {
                override fun onReceive(ctx: Context?, intent: Intent?) {
                    if (intent?.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1) == downloadId) {
                        unregisterReceiver(this)
                        installApk()
                    }
                }
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                registerReceiver(receiver, IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE), RECEIVER_NOT_EXPORTED)
            } else {
                @Suppress("UnspecifiedRegisterReceiverFlag")
                registerReceiver(receiver, IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE))
            }
        } catch (e: Exception) {
            Toast.makeText(this, "Errore download: ${e.message}", Toast.LENGTH_LONG).show()
        }
    }

    private fun installApk() {
        try {
            val file = java.io.File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS), "evershelf-update.apk")
            val uri = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
                androidx.core.content.FileProvider.getUriForFile(this, "$packageName.provider", file)
            } else {
                Uri.fromFile(file)
            }
            val install = Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(uri, "application/vnd.android.package-archive")
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            startActivity(install)
        } catch (e: Exception) {
            Toast.makeText(this, "Errore installazione: ${e.message}", Toast.LENGTH_LONG).show()
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
            checkGatewayStatus()
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
