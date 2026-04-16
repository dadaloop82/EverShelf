package it.dadaloop.evershelf.kiosk

import android.Manifest
import android.annotation.SuppressLint
import android.bluetooth.BluetoothAdapter
import android.content.*
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.IBinder
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.webkit.*
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import it.dadaloop.evershelf.kiosk.databinding.ActivityKioskBinding

private const val PREFS_NAME = "evershelf_kiosk"
private const val PREF_URL = "evershelf_url"
private const val DEFAULT_URL = "http://evershelf.local"

class KioskActivity : AppCompatActivity() {

    private lateinit var binding: ActivityKioskBinding
    private var gatewayService: ScaleGatewayService? = null
    private var bound = false

    private val serviceConnection = object : ServiceConnection {
        override fun onServiceConnected(name: ComponentName, service: IBinder) {
            val binder = service as ScaleGatewayService.LocalBinder
            gatewayService = binder.getService()
            bound = true
        }

        override fun onServiceDisconnected(name: ComponentName) {
            gatewayService = null
            bound = false
        }
    }

    // Permission request launcher
    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { results ->
        val allGranted = results.all { it.value }
        if (allGranted) {
            startGatewayService()
        } else {
            Toast.makeText(this, "BLE permissions required for scale gateway", Toast.LENGTH_LONG).show()
            // Start anyway without BLE
            startGatewayService()
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityKioskBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Full-screen immersive mode
        enterKioskMode()

        // Keep screen on
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        // Setup WebView
        setupWebView()

        // Settings button (long press corner area)
        binding.btnSettings.setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }

        // Request permissions and start gateway
        requestPermissionsAndStart()

        // Load the EverShelf URL
        loadEverShelfUrl()
    }

    override fun onResume() {
        super.onResume()
        enterKioskMode()
        // Reload URL in case it was changed in settings
        val currentUrl = binding.webView.url ?: ""
        val savedUrl = getSavedUrl()
        if (currentUrl.isNotEmpty() && !currentUrl.startsWith(savedUrl)) {
            loadEverShelfUrl()
        }
    }

    override fun onDestroy() {
        if (bound) {
            unbindService(serviceConnection)
            bound = false
        }
        super.onDestroy()
    }

    private fun enterKioskMode() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.insetsController?.let {
                it.hide(WindowInsets.Type.statusBars() or WindowInsets.Type.navigationBars())
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

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        binding.webView.apply {
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.databaseEnabled = true
            settings.mediaPlaybackRequiresUserGesture = false
            settings.allowFileAccess = false
            settings.allowContentAccess = false
            settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            settings.cacheMode = WebSettings.LOAD_DEFAULT
            settings.useWideViewPort = true
            settings.loadWithOverviewMode = true
            settings.setSupportZoom(false)
            settings.builtInZoomControls = false

            // Allow camera access for barcode scanning
            webChromeClient = object : WebChromeClient() {
                override fun onPermissionRequest(request: PermissionRequest) {
                    runOnUiThread {
                        request.grant(request.resources)
                    }
                }

                override fun onConsoleMessage(consoleMessage: ConsoleMessage?): Boolean {
                    return true
                }
            }

            webViewClient = object : WebViewClient() {
                override fun onReceivedError(
                    view: WebView?,
                    request: WebResourceRequest?,
                    error: WebResourceError?
                ) {
                    // Show retry page on load error
                    if (request?.isForMainFrame == true) {
                        view?.loadData(
                            """
                            <html><body style="font-family:sans-serif;text-align:center;padding:40px;background:#1a1a2e;color:#fff">
                            <h2>⚠️ Connection Error</h2>
                            <p>Cannot reach EverShelf server</p>
                            <p style="color:#888;font-size:14px">${getSavedUrl()}</p>
                            <button onclick="location.reload()" style="padding:12px 24px;font-size:16px;border:none;border-radius:8px;background:#7C3AED;color:#fff;cursor:pointer;margin-top:20px">Retry</button>
                            <br><br>
                            <button onclick="window.location='evershelf://settings'" style="padding:8px 16px;font-size:14px;border:1px solid #666;border-radius:8px;background:transparent;color:#aaa;cursor:pointer">Settings</button>
                            </body></html>
                            """.trimIndent(),
                            "text/html", "utf-8"
                        )
                    }
                }

                override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                    val url = request.url.toString()
                    if (url.startsWith("evershelf://settings")) {
                        startActivity(Intent(this@KioskActivity, SettingsActivity::class.java))
                        return true
                    }
                    // Keep navigation within the WebView for same-origin
                    return false
                }
            }
        }
    }

    private fun loadEverShelfUrl() {
        val url = getSavedUrl()
        binding.webView.loadUrl(url)
    }

    private fun getSavedUrl(): String {
        return getSharedPreferences(PREFS_NAME, MODE_PRIVATE)
            .getString(PREF_URL, DEFAULT_URL) ?: DEFAULT_URL
    }

    private fun requestPermissionsAndStart() {
        val needed = mutableListOf<String>()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_SCAN)
                != PackageManager.PERMISSION_GRANTED) needed.add(Manifest.permission.BLUETOOTH_SCAN)
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_CONNECT)
                != PackageManager.PERMISSION_GRANTED) needed.add(Manifest.permission.BLUETOOTH_CONNECT)
        } else {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
                != PackageManager.PERMISSION_GRANTED) needed.add(Manifest.permission.ACCESS_FINE_LOCATION)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED) needed.add(Manifest.permission.POST_NOTIFICATIONS)
        }

        if (needed.isNotEmpty()) {
            permissionLauncher.launch(needed.toTypedArray())
        } else {
            startGatewayService()
        }
    }

    private fun startGatewayService() {
        val intent = Intent(this, ScaleGatewayService::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(intent)
        } else {
            startService(intent)
        }
        bindService(intent, serviceConnection, Context.BIND_AUTO_CREATE)
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        if (binding.webView.canGoBack()) {
            binding.webView.goBack()
        }
        // Don't call super — prevent exiting kiosk mode
    }
}
