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
import android.widget.ImageButton
import android.widget.LinearLayout
import android.widget.ProgressBar
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

    // TTS
    private var tts: TextToSpeech? = null
    private var ttsReady = false

    // Views
    private lateinit var splashContainer: LinearLayout
    private lateinit var webView: WebView
    private lateinit var btnSettings: ImageButton
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
    private var activeInstallBtn: MaterialButton? = null
    private val pollHandler = Handler(Looper.getMainLooper())
    private var activeDownloadId: Long = -1

    // File chooser
    private var fileChooserCallback: ValueCallback<Array<Uri>>? = null

    // Pending WebView permission request
    private var pendingWebPermission: PermissionRequest? = null

    companion object {
        private const val FILE_CHOOSER_REQUEST    = 1002
        private const val PERMISSION_REQUEST_CODE = 1003
        private const val INSTALL_PERM_REQUEST    = 1004
        private const val INSTALL_CONFIRM_REQUEST = 1005
        private const val UNINSTALL_REQUEST       = 1006
        private const val SETUP_REQUEST           = 1007
        private const val PREFS_NAME = "evershelf_kiosk"
        private const val KEY_URL = "evershelf_url"
        private const val KEY_SETUP_COMPLETE = "setup_complete"
        private const val KEY_HAS_SCALE = "has_scale"
        private const val KEY_SCREENSAVER = "screensaver_enabled"
        private const val GATEWAY_PACKAGE = "it.dadaloop.evershelf.scalegate"
        private const val GATEWAY_DOWNLOAD_URL = "https://github.com/dadaloop82/EverShelf/releases/latest/download/evershelf-scale-gateway.apk"
        private const val KIOSK_DOWNLOAD_URL = "https://github.com/dadaloop82/EverShelf/releases/download/kiosk-latest/evershelf-kiosk.apk"
        private const val SPLASH_DURATION = 1500L
        private const val GITHUB_RELEASES_API = "https://api.github.com/repos/dadaloop82/EverShelf/releases/latest"
    }

    override fun attachBaseContext(newBase: Context) {
        val lang = newBase.getSharedPreferences("evershelf_kiosk", Context.MODE_PRIVATE)
            .getString("kiosk_language", null)
        super.attachBaseContext(if (lang != null) SetupActivity.applyLocale(newBase, lang) else newBase)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_kiosk)

        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        bindViews()
        enterImmersiveMode()

        val savedUrl = prefs.getString(KEY_URL, "") ?: ""
        ErrorReporter.init(this, savedUrl)

        tts = TextToSpeech(this) { status ->
            if (status == TextToSpeech.SUCCESS) {
                val res = tts?.setLanguage(Locale.ITALIAN)
                if (res == TextToSpeech.LANG_MISSING_DATA || res == TextToSpeech.LANG_NOT_SUPPORTED) {
                    tts?.language = Locale.getDefault()
                }
                ttsReady = true
            }
        }

        if (!prefs.getBoolean(KEY_SETUP_COMPLETE, false)) {
            // Skip splash — SetupActivity has its own welcome screen
            splashContainer.visibility = View.GONE
            @Suppress("DEPRECATION")
            startActivityForResult(Intent(this, SetupActivity::class.java), SETUP_REQUEST)
        } else {
            enableKioskLock()
            Handler(Looper.getMainLooper()).postDelayed({
                splashContainer.visibility = View.GONE
                launchWebView()
            }, SPLASH_DURATION)
        }
    }

    private fun bindViews() {
        splashContainer = findViewById(R.id.splashContainer)
        webView         = findViewById(R.id.webView)
        btnSettings     = findViewById(R.id.btnSettings)

        updateBanner         = findViewById(R.id.updateBanner)
        tvUpdateMessage      = findViewById(R.id.tvUpdateMessage)
        btnInstallUpdate     = findViewById(R.id.btnInstallUpdate)
        btnDismissUpdate     = findViewById(R.id.btnDismissUpdate)
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

        btnSettings.setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }
        btnSettings.setOnLongClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
            true
        }
    }

    // ── Runtime Permissions (for WebView camera/mic) ─────────────────────

    private fun requestAllPermissions() {
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
        if (needed.isNotEmpty()) {
            ActivityCompat.requestPermissions(this, needed.toTypedArray(), PERMISSION_REQUEST_CODE)
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == PERMISSION_REQUEST_CODE) {
            pendingWebPermission?.let { req ->
                if (grantResults.all { it == PackageManager.PERMISSION_GRANTED }) req.grant(req.resources)
                else req.deny()
                pendingWebPermission = null
            }
        }
    }

    // ── Kiosk Lock ────────────────────────────────────────────────────────

    private fun enableKioskLock() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            startLockTask()
        }
    }

    private fun disableKioskLock() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            try { stopLockTask() } catch (_: Exception) {}
        }
    }

    // ── Gateway ────────────────────────────────────────────────────────────

    private fun isGatewayInstalled(): Boolean {
        return try {
            packageManager.getPackageInfo(GATEWAY_PACKAGE, 0)
            true
        } catch (e: PackageManager.NameNotFoundException) { false }
    }

    private fun launchGatewayInBackground() {
        if (!prefs.getBoolean(KEY_HAS_SCALE, false)) return
        if (!isGatewayInstalled()) return
        val launchIntent = packageManager.getLaunchIntentForPackage(GATEWAY_PACKAGE) ?: return
        launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        startActivity(launchIntent)
        Handler(Looper.getMainLooper()).postDelayed({
            val am = getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
            am.moveTaskToFront(taskId, ActivityManager.MOVE_TASK_WITH_HOME)
        }, 1500)
    }

    // ── Install UI ────────────────────────────────────────────────────────

    private fun setInstallUI(
        icon: String, title: String, detail: String, color: Int,
        btnEnabled: Boolean = false,
        progress: Int = -2,
        progressText: String = ""
    ) = runOnUiThread {
        // Update banner
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
        activeInstallBtn?.let { btn ->
            btn.isEnabled = btnEnabled
            btn.text = "$icon  $title"
        }
    }

    // ── Download Progress Poll ────────────────────────────────────────────

    private fun startDownloadProgressPoll(downloadId: Long) {
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
                val dlMb  = dl  / 1_048_576f
                val totMb = tot / 1_048_576f
                val txt = if (tot > 0) "%.1f MB / %.1f MB".format(dlMb, totMb) else ""
                setInstallUI(
                    "\u23F3",
                    getString(R.string.install_downloading) + if (tot > 0) " ($pct%)" else "",
                    txt, 0xFF94a3b8.toInt(),
                    btnEnabled = false, progress = pct, progressText = txt
                )
                pollHandler.postDelayed({ tick() }, 500)
            } else {
                c.close()
            }
        }
        pollHandler.post { tick() }
    }

    // ── WebView ────────────────────────────────────────────────────────────

    @SuppressLint("SetJavaScriptEnabled")
    private fun launchWebView() {
        // Ensure kiosk lock and permissions are active
        enableKioskLock()
        requestAllPermissions()

        webView.visibility   = View.VISIBLE
        btnSettings.visibility = View.VISIBLE

        val settings = webView.settings
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true
        settings.mediaPlaybackRequiresUserGesture = false
        settings.allowFileAccess = true
        settings.mixedContentMode = android.webkit.WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
        settings.cacheMode = android.webkit.WebSettings.LOAD_NO_CACHE

        webView.webViewClient = object : WebViewClient() {
            override fun onReceivedSslError(view: WebView?, handler: SslErrorHandler?, error: SslError?) {
                handler?.proceed()
            }
            override fun onReceivedError(view: WebView?, request: WebResourceRequest?, error: WebResourceError?) {
                val errorDesc = error?.description?.toString() ?: "unknown"
                val errorCode = error?.errorCode ?: -1
                val url = request?.url?.toString() ?: ""
                if (request?.isForMainFrame == true) {
                    ErrorReporter.reportMessage(
                        type = "webview-load-error",
                        message = "WebView failed to load main frame: $errorDesc (code $errorCode)",
                        extra = mapOf("url" to url, "errorCode" to errorCode)
                    )
                    view?.loadData(errorPageHtml(), "text/html", "UTF-8")
                }
            }
            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                injectKioskOverlay()
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
                                if (ContextCompat.checkSelfPermission(this@KioskActivity, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED)
                                    needed.add(Manifest.permission.CAMERA)
                            }
                            PermissionRequest.RESOURCE_AUDIO_CAPTURE -> {
                                if (ContextCompat.checkSelfPermission(this@KioskActivity, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED)
                                    needed.add(Manifest.permission.RECORD_AUDIO)
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
                if (msg != null && msg.messageLevel() == ConsoleMessage.MessageLevel.ERROR) {
                    ErrorReporter.reportMessage(
                        type = "webview-js-error",
                        message = msg.message(),
                        extra = mapOf("source_id" to msg.sourceId(), "line" to msg.lineNumber())
                    )
                }
                return true
            }
            override fun onShowFileChooser(
                wv: WebView?, callback: ValueCallback<Array<Uri>>?,
                params: FileChooserParams?
            ): Boolean {
                fileChooserCallback?.onReceiveValue(null)
                fileChooserCallback = callback
                val intent = params?.createIntent()
                if (intent != null) {
                    @Suppress("DEPRECATION")
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST)
                }
                return true
            }
        }

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
            @JavascriptInterface
            fun speak(text: String, rate: Float, pitch: Float) {
                val engine = tts ?: return
                if (!ttsReady) return
                engine.setSpeechRate(rate.coerceIn(0.1f, 4f))
                engine.setPitch(pitch.coerceIn(0.1f, 4f))
                engine.speak(text, android.speech.tts.TextToSpeech.QUEUE_FLUSH, null, "kiosk_tts")
            }
            @JavascriptInterface
            fun stopSpeech() { tts?.stop() }
            @JavascriptInterface
            fun isTtsReady(): String = if (ttsReady) "true" else "false"
        }, "_kioskBridge")

        val url = prefs.getString(KEY_URL, "http://evershelf.local") ?: "http://evershelf.local"
        webView.loadUrl(url)

        launchGatewayInBackground()
        applyScreensaverFlag()
    }

    private fun applyScreensaverFlag() {
        // The kiosk screen must ALWAYS stay on — the in-app screensaver is a JS overlay
        // (clock + info) shown by the webapp after inactivity, not an Android screen timeout.
        // Never clear FLAG_KEEP_SCREEN_ON regardless of the screensaver preference.
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
    }

    // ── Inject kiosk overlay (exit + refresh buttons) ────────────────────

    private fun injectKioskOverlay() {
        val js = """
        (function() {
            if (document.getElementById('_kiosk_overlay')) return;
            var wrap = document.createElement('div');
            wrap.id = '_kiosk_overlay';
            wrap.style.cssText = 'position:fixed;top:8px;left:8px;z-index:2147483647;display:flex;gap:6px;align-items:center;pointer-events:auto;';
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
        if (now - lastCheck < 6 * 60 * 60 * 1000) return
        prefs.edit().putLong("last_update_check", now).apply()

        Thread {
            try {
                val conn = URL(GITHUB_RELEASES_API).openConnection() as java.net.HttpURLConnection
                conn.setRequestProperty("Accept", "application/vnd.github+json")
                conn.connectTimeout = 5000
                conn.readTimeout    = 5000
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

                val norm = { v: String -> v.trimStart('v') }
                val isSemver = latestTag.trimStart('v').matches(Regex("\\d+\\.\\d+.*"))

                val assets = json.optJSONArray("assets")
                var kioskApkUrl   = ""
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

                val kioskNeedsUpdate   = kioskApkUrl.isNotEmpty() && currentKiosk.isNotEmpty() &&
                    (!isSemver || norm(latestTag) != norm(currentKiosk))
                val gatewayNeedsUpdate = currentGateway != null && gatewayApkUrl.isNotEmpty() &&
                    (!isSemver || norm(latestTag) != norm(currentGateway))

                if (!kioskNeedsUpdate && !gatewayNeedsUpdate) return@Thread

                val lines = mutableListOf<String>()
                var primaryApkUrl = ""
                if (kioskNeedsUpdate) {
                    val label = if (isSemver) "$currentKiosk → $latestTag" else latestTag
                    lines += "\uD83D\uDD04 Kiosk $label"
                    primaryApkUrl = kioskApkUrl
                }
                if (gatewayNeedsUpdate) {
                    val label = if (isSemver) "$currentGateway → $latestTag" else latestTag
                    lines += "\uD83D\uDD04 Scale Gateway $label"
                    if (primaryApkUrl.isEmpty()) primaryApkUrl = gatewayApkUrl
                }
                val message = lines.joinToString("  •  ")
                runOnUiThread { showNativeUpdateBanner(message, primaryApkUrl) }
            } catch (_: Exception) { }
        }.start()
    }

    private fun showNativeUpdateBanner(message: String, apkDownloadUrl: String) {
        pendingApkDownloadUrl = apkDownloadUrl
        tvUpdateMessage.text = "⬆️ Aggiornamento disponibile:  $message"
        updateBanner.visibility = View.VISIBLE
        updateBanner.postDelayed({ updateBanner.visibility = View.GONE }, 30_000)
    }

    // ── APK Download + Install ─────────────────────────────────────────────

    private fun triggerApkDownload(apkUrl: String) {
        if (apkUrl.isEmpty()) return
        pendingApkDownloadUrl = apkUrl
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !packageManager.canRequestPackageInstalls()) {
            setInstallUI("\uD83D\uDD12", getString(R.string.install_perm_detail), getString(R.string.install_perm_detail), 0xFFfbbf24.toInt(), btnEnabled = false)
            @Suppress("DEPRECATION")
            startActivityForResult(
                Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:$packageName")),
                INSTALL_PERM_REQUEST
            )
            return
        }
        setInstallUI("\u23F3", getString(R.string.install_downloading), getString(R.string.install_downloading_detail), 0xFF94a3b8.toInt(), btnEnabled = false)
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
                val q  = DownloadManager.Query().setFilterById(downloadId)
                val c  = (getSystemService(DOWNLOAD_SERVICE) as DownloadManager).query(q)
                var ok = false
                if (c.moveToFirst()) ok = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS)) == DownloadManager.STATUS_SUCCESSFUL
                c.close()
                if (ok) {
                    pollHandler.removeCallbacksAndMessages(null); activeDownloadId = -1
                    setInstallUI("\u23F3", getString(R.string.install_installing), getString(R.string.install_installing), 0xFF94a3b8.toInt(), btnEnabled = false, progress = -1)
                    installApk(destFile)
                } else {
                    pollHandler.removeCallbacksAndMessages(null); activeDownloadId = -1
                    setInstallUI("\u274C", getString(R.string.install_error_download), getString(R.string.install_error_download_detail), 0xFFf87171.toInt(), btnEnabled = true, progress = -2)
                    runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
                    ErrorReporter.reportMessage("install_download_failed", "DownloadManager returned failure for URL: $apkUrl")
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
            setInstallUI("\u274C", getString(R.string.install_error_download), "File APK non trovato sul dispositivo.", 0xFFf87171.toInt(), btnEnabled = true)
            runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
            return
        }
        val magic: ByteArray? = try { file.inputStream().use { s -> val b = ByteArray(4); s.read(b); b } } catch (_: Exception) { null }
        val isApk = magic != null && magic[0] == 0x50.toByte() && magic[1] == 0x4B.toByte()
        if (!isApk) {
            setInstallUI("\u274C", getString(R.string.install_error_download), "Il file scaricato non è un APK valido.", 0xFFf87171.toInt(), btnEnabled = true, progress = -2)
            runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
            ErrorReporter.reportMessage("install_invalid_apk", "Downloaded file is not a valid APK. URL=$pendingApkDownloadUrl size=${file.length()}")
            file.delete()
            return
        }
        val targetPkg = when {
            pendingApkDownloadUrl.contains("gateway", ignoreCase = true) ||
            pendingApkDownloadUrl.contains("scale",   ignoreCase = true) -> GATEWAY_PACKAGE
            else -> packageName
        }
        installWithPackageInstaller(file, targetPkg)
    }

    private fun installWithPackageInstaller(file: java.io.File, targetPkg: String) {
        try {
            val pi     = packageManager.packageInstaller
            val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL)
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
            val action = "it.dadaloop.evershelf.kiosk.INSTALL_RESULT_$sessionId"
            val resultReceiver = object : BroadcastReceiver() {
                override fun onReceive(ctx: Context?, intent: Intent?) {
                    val status = intent?.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE) ?: PackageInstaller.STATUS_FAILURE
                    when (status) {
                        PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                            // Do NOT unregister here — the final result arrives as a second broadcast
                            @Suppress("DEPRECATION")
                            val confirmIntent = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU)
                                intent?.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java)
                            else intent?.getParcelableExtra(Intent.EXTRA_INTENT)
                            if (confirmIntent != null) {
                                pendingInstallFile = file
                                pendingInstallPkg  = targetPkg
                                setInstallUI("\u23F3", getString(R.string.install_installing), getString(R.string.install_confirm_detail), 0xFF94a3b8.toInt(), btnEnabled = false)
                                @Suppress("DEPRECATION")
                                startActivityForResult(confirmIntent, INSTALL_CONFIRM_REQUEST)
                            } else {
                                unregisterReceiver(this)
                                setInstallUI("\u274C", getString(R.string.install_error_install), "No confirmation intent", 0xFFf87171.toInt(), btnEnabled = true, progress = -2)
                            }
                        }
                        PackageInstaller.STATUS_SUCCESS -> {
                            unregisterReceiver(this)
                            setInstallUI("\u2705", getString(R.string.install_success), getString(R.string.install_success_detail), 0xFF34d399.toInt(), btnEnabled = false, progress = -2)
                            Handler(Looper.getMainLooper()).postDelayed({
                                updateBanner.visibility = View.GONE
                                bannerProgressBar.visibility = View.GONE
                            }, 3000)
                        }
                        PackageInstaller.STATUS_FAILURE_INCOMPATIBLE,
                        PackageInstaller.STATUS_FAILURE_CONFLICT -> {
                            unregisterReceiver(this)
                            runOnUiThread {
                                pendingInstallFile = file
                                pendingInstallPkg  = targetPkg
                                androidx.appcompat.app.AlertDialog.Builder(this@KioskActivity)
                                    .setTitle("⚠️ Conflitto firma APK")
                                    .setMessage("L'app installata usa una firma diversa.\n\nDisinstalla la versione precedente: al termine l'installazione riparte automaticamente.")
                                    .setPositiveButton("Disinstalla") { _, _ ->
                                        disableKioskLock()
                                        @Suppress("DEPRECATION")
                                        startActivityForResult(Intent(Intent.ACTION_DELETE, android.net.Uri.parse("package:$targetPkg")), UNINSTALL_REQUEST)
                                    }
                                    .setNegativeButton("Annulla", null).show()
                            }
                        }
                        -1 /* STATUS_FAILURE_ABORTED */ -> {
                            unregisterReceiver(this)
                            runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
                        }
                        else -> {
                            unregisterReceiver(this)
                            val msg = intent?.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE) ?: ""
                            val deviceLabel = buildDeviceLabel()
                            val diagInfo = buildString {
                                appendLine("Status: $status — ${installStatusHint(status)}")
                                if (msg.isNotEmpty()) appendLine("Msg: $msg")
                                appendLine("PKG: $targetPkg")
                                appendLine("APK: ${file.length() / 1024} KB")
                                appendLine("Android: ${Build.VERSION.SDK_INT} (${Build.VERSION.RELEASE})")
                                appendLine("Device: $deviceLabel")
                            }
                            setInstallUI("\u274C", getString(R.string.install_error_install),
                                diagInfo.trim(), 0xFFf87171.toInt(), btnEnabled = true, progress = -2)
                            runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
                            ErrorReporter.reportMessage(
                                "install_failure",
                                "PackageInstaller status=$status pkg=$targetPkg android=${Build.VERSION.SDK_INT}",
                                mapOf(
                                    "pkg"     to targetPkg,
                                    "status"  to status,
                                    "msg"     to msg,
                                    "apk_kb"  to (file.length() / 1024),
                                    "android" to Build.VERSION.SDK_INT,
                                    "device"  to deviceLabel
                                ),
                                forceReport = true
                            )
                            val pkgInstalled = try { packageManager.getPackageInfo(targetPkg, 0); true } catch (_: Exception) { false }
                            if (pkgInstalled) {
                                runOnUiThread {
                                    pendingInstallFile = file
                                    pendingInstallPkg  = targetPkg
                                    androidx.appcompat.app.AlertDialog.Builder(this@KioskActivity)
                                        .setTitle("⚠️ Installazione fallita (status=$status)")
                                        .setMessage(diagInfo.trim())
                                        .setPositiveButton("Disinstalla e riprova") { _, _ ->
                                            disableKioskLock()
                                            @Suppress("DEPRECATION")
                                            startActivityForResult(Intent(Intent.ACTION_DELETE, android.net.Uri.parse("package:$targetPkg")), UNINSTALL_REQUEST)
                                        }
                                        .setNegativeButton("Annulla", null).show()
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
            setInstallUI("\u23F3", getString(R.string.install_installing), getString(R.string.install_installing), 0xFF94a3b8.toInt(), btnEnabled = false, progress = -1)
        } catch (e: Exception) {
            setInstallUI("\u274C", getString(R.string.install_error_install), e.message ?: "", 0xFFf87171.toInt(), btnEnabled = true, progress = -2)
            runOnUiThread { activeInstallBtn?.text = getString(R.string.install_btn_retry) }
            ErrorReporter.reportMessage("install_packager_exception",
                "installWithPackageInstaller exception for $targetPkg: ${e.message}",
                forceReport = true)
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

    private fun installStatusHint(status: Int): String = when (status) {
        1    -> "Errore generico (APK incompatibile con questo dispositivo o versione Android)"
        2    -> "Bloccato da policy o da un'altra app in corso"
        3    -> "Annullato dall'utente"
        4    -> "APK non valido o corrotto"
        5    -> "Conflitto: versione precedente con firma diversa"
        6    -> "Spazio insufficiente"
        7    -> "Incompatibile con questa versione di Android"
        else -> "Errore sconosciuto"
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
            if (url.isNotEmpty() && webView.url != url) webView.loadUrl(url)
            // Re-apply screensaver flag in case the user changed it in Settings
            applyScreensaverFlag()
        }
    }

    @Suppress("DEPRECATION")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)

        // Setup wizard completed
        if (requestCode == SETUP_REQUEST) {
            if (resultCode == RESULT_OK) {
                val newUrl = prefs.getString(KEY_URL, "") ?: ""
                ErrorReporter.init(this, newUrl)
                enableKioskLock()
                launchWebView()
            } else {
                // User exited setup without completing — close app
                finishAffinity()
            }
            return
        }

        if (requestCode == FILE_CHOOSER_REQUEST) {
            val result = if (resultCode == RESULT_OK && data != null)
                WebChromeClient.FileChooserParams.parseResult(resultCode, data) else null
            fileChooserCallback?.onReceiveValue(result)
            fileChooserCallback = null
        }
        if (requestCode == INSTALL_PERM_REQUEST) {
            val url = pendingApkDownloadUrl
            if (url.isNotEmpty()) triggerApkDownload(url)
        }
        if (requestCode == INSTALL_CONFIRM_REQUEST && resultCode == RESULT_OK) {
            setInstallUI("\u2705", getString(R.string.install_success), getString(R.string.install_success_detail), 0xFF34d399.toInt(), btnEnabled = false, progress = -2)
            Handler(Looper.getMainLooper()).postDelayed({ updateBanner.visibility = View.GONE; bannerProgressBar.visibility = View.GONE }, 3000)
        }
        if (requestCode == INSTALL_CONFIRM_REQUEST && resultCode != RESULT_OK) {
            val f = pendingInstallFile; val pkg = pendingInstallPkg
            if (f != null && f.exists() && pkg.isNotEmpty()) {
                runOnUiThread {
                    androidx.appcompat.app.AlertDialog.Builder(this)
                        .setTitle("⚠️ Installazione non riuscita")
                        .setMessage("Se hai visto un errore di conflitto firma, devi disinstallare la versione precedente.\n\nDisinstalla ora?")
                        .setPositiveButton("Disinstalla") { _, _ ->
                            disableKioskLock()
                            startActivityForResult(Intent(Intent.ACTION_DELETE, android.net.Uri.parse("package:$pkg")), UNINSTALL_REQUEST)
                        }
                        .setNegativeButton("Annulla", null).show()
                }
            }
        }
        if (requestCode == UNINSTALL_REQUEST) {
            enableKioskLock()
            val f = pendingInstallFile; val pkg = pendingInstallPkg
            if (f != null && f.exists() && pkg.isNotEmpty()) {
                Handler(Looper.getMainLooper()).postDelayed({ installWithPackageInstaller(f, pkg) }, 600)
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
        // Back button blocked in kiosk mode
    }
}
