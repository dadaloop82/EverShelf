package it.dadaloop.evershelf.kiosk

import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.content.SharedPreferences
import android.content.pm.PackageManager
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.net.http.SslError
import android.os.Build
import android.os.Bundle
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.webkit.ConsoleMessage
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
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton
import java.net.URL
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager

class KioskActivity : AppCompatActivity() {

    private lateinit var prefs: SharedPreferences
    private var currentStep = 1

    // Views
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

    // File chooser
    private var fileChooserCallback: ValueCallback<Array<Uri>>? = null

    companion object {
        private const val FILE_CHOOSER_REQUEST = 1002
        private const val PREFS_NAME = "evershelf_kiosk"
        private const val KEY_URL = "evershelf_url"
        private const val KEY_SETUP_COMPLETE = "setup_complete"
        private const val GATEWAY_PACKAGE = "it.dadaloop.evershelf.scalegate"
        private const val GATEWAY_DOWNLOAD_URL = "https://github.com/dadaloop82/EverShelf/releases/latest/download/evershelf-scale-gateway.apk"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_kiosk)

        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        bindViews()
        enterImmersiveMode()

        if (prefs.getBoolean(KEY_SETUP_COMPLETE, false)) {
            launchWebView()
        } else {
            showWizard()
        }
    }

    private fun bindViews() {
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

        // Step 1 buttons
        findViewById<MaterialButton>(R.id.btnGetStarted).setOnClickListener {
            goToStep(2)
        }

        // Step 2 buttons
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

        // Step 3 buttons
        findViewById<MaterialButton>(R.id.btnStep3Back).setOnClickListener {
            goToStep(2)
        }
        findViewById<MaterialButton>(R.id.btnFinish).setOnClickListener {
            launchGatewayIfInstalled()
            finishWizard()
        }
        findViewById<MaterialButton>(R.id.btnSkipScale).setOnClickListener {
            finishWizard()
        }

        // Settings button
        btnSettings.setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }

        // Pre-fill URL if saved
        val savedUrl = prefs.getString(KEY_URL, "") ?: ""
        if (savedUrl.isNotEmpty()) {
            wizardUrl.setText(savedUrl)
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
            if (i == currentStep) {
                bg.setColor(0xFF7c3aed.toInt())
            } else if (i < currentStep) {
                bg.setColor(0xFF34d399.toInt())
            } else {
                bg.setColor(0xFF334155.toInt())
            }
            dot.background = bg
            stepIndicator.addView(dot)
        }
    }

    private fun finishWizard() {
        prefs.edit().putBoolean(KEY_SETUP_COMPLETE, true).apply()
        wizardContainer.visibility = View.GONE
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

    private fun launchGatewayIfInstalled() {
        if (isGatewayInstalled()) {
            val launchIntent = packageManager.getLaunchIntentForPackage(GATEWAY_PACKAGE)
            if (launchIntent != null) {
                launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                startActivity(launchIntent)
            }
        }
    }

    private fun checkGatewayStatus() {
        if (isGatewayInstalled()) {
            scaleStatusIcon.text = "✅"
            scaleStatusText.text = "Scale Gateway is installed"
            scaleStatusDetail.text = "It will be launched automatically when you finish setup"
            scaleStatusDetail.setTextColor(0xFF34d399.toInt())
            // Hide skip, show finish prominently
            findViewById<MaterialButton>(R.id.btnSkipScale).visibility = View.GONE
        } else {
            scaleStatusIcon.text = "📥"
            scaleStatusText.text = "Scale Gateway not installed"
            scaleStatusDetail.text = "You need the EverShelf Scale Gateway app to use a Bluetooth scale"
            scaleStatusDetail.setTextColor(0xFFfbbf24.toInt())

            // Show download button in the card
            val downloadBtn = findViewById<MaterialButton>(R.id.btnFinish)
            downloadBtn.text = "🚀  Launch EverShelf (without scale)"

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
                val testUrl = if (url.endsWith("/")) "${url}api/" else "${url}/api/"
                val conn = URL(testUrl).openConnection()

                // Trust all certs for local/self-signed servers
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
                        if (code in 200..299) {
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

        webView.webViewClient = object : WebViewClient() {
            override fun onReceivedSslError(
                view: WebView?, handler: SslErrorHandler?, error: SslError?
            ) {
                // Accept self-signed certs for local network servers
                handler?.proceed()
            }

            override fun onReceivedError(
                view: WebView?, request: WebResourceRequest?,
                error: WebResourceError?
            ) {
                if (request?.isForMainFrame == true) {
                    view?.loadData(errorPageHtml(), "text/html", "UTF-8")
                }
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onPermissionRequest(request: PermissionRequest?) {
                runOnUiThread { request?.grant(request.resources) }
            }
            override fun onConsoleMessage(msg: ConsoleMessage?): Boolean = true
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

        val url = prefs.getString(KEY_URL, "http://evershelf.local") ?: "http://evershelf.local"
        webView.loadUrl(url)

        // Launch gateway app if installed (handles scale in background)
        launchGatewayIfInstalled()

        // Keep screen on
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
    }

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
        if (!prefs.getBoolean(KEY_SETUP_COMPLETE, false) && wizardContainer.visibility != View.VISIBLE) {
            showWizard()
        }
        // Re-check gateway status if on step 3
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

    override fun onBackPressed() {
        if (webView.visibility == View.VISIBLE && webView.canGoBack()) {
            webView.goBack()
        }
        // Block back button in kiosk mode
    }
}
