package it.dadaloop.evershelf.kiosk

import android.content.Context
import android.content.Intent
import android.content.SharedPreferences
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import android.view.WindowManager
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton
import com.google.android.material.switchmaterial.SwitchMaterial
import java.net.URL
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager

class SettingsActivity : AppCompatActivity() {

    private lateinit var prefs: SharedPreferences
    private lateinit var urlEdit: EditText

    companion object {
        private const val PREFS_NAME = "evershelf_kiosk"
        private const val KEY_URL = "evershelf_url"
        private const val KEY_SETUP_COMPLETE = "setup_complete"
        private const val KEY_SCREENSAVER = "screensaver_enabled"
        private const val GATEWAY_PACKAGE = "it.dadaloop.evershelf.scalegate"
    }

    override fun attachBaseContext(newBase: Context) {
        val lang = newBase.getSharedPreferences("evershelf_kiosk", Context.MODE_PRIVATE)
            .getString("kiosk_language", null)
        super.attachBaseContext(if (lang != null) SetupActivity.applyLocale(newBase, lang) else newBase)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_settings)

        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        urlEdit = findViewById(R.id.urlEdit)

        urlEdit.setText(prefs.getString(KEY_URL, "") ?: "")

        // Screensaver toggle (default OFF = keep screen on)
        val switchScreensaver = findViewById<SwitchMaterial>(R.id.switchScreensaver)
        switchScreensaver.isChecked = prefs.getBoolean(KEY_SCREENSAVER, false)

        // Gateway status
        val gatewayInstalled = try {
            packageManager.getPackageInfo(GATEWAY_PACKAGE, 0)
            true
        } catch (e: PackageManager.NameNotFoundException) {
            false
        }
        val statusView = findViewById<TextView>(R.id.scaleGatewayStatus)
        val deviceView = findViewById<TextView>(R.id.scaleDeviceInfo)
        if (gatewayInstalled) {
            statusView.text = "Installed"
            statusView.setTextColor(0xFF34d399.toInt())
            deviceView.text = "EverShelf Scale Gateway app is installed"
        } else {
            statusView.text = "Not installed"
            statusView.setTextColor(0xFFfbbf24.toInt())
            deviceView.text = "Install the Scale Gateway app to use a Bluetooth scale"
        }

        val btnConfigureGateway = findViewById<MaterialButton>(R.id.btnConfigureGateway)
        if (gatewayInstalled) {
            btnConfigureGateway.visibility = android.view.View.VISIBLE
            btnConfigureGateway.setOnClickListener {
                val intent = packageManager.getLaunchIntentForPackage(GATEWAY_PACKAGE)
                if (intent != null) startActivity(intent)
            }
        } else {
            btnConfigureGateway.visibility = android.view.View.GONE
        }

        // Back
        findViewById<android.widget.ImageButton>(R.id.btnBack).setOnClickListener { finish() }

        // Test connection
        findViewById<MaterialButton>(R.id.btnTestConnection).setOnClickListener { testConnection() }

        // Run wizard again
        findViewById<MaterialButton>(R.id.btnRunWizard).setOnClickListener {
            prefs.edit().putBoolean(KEY_SETUP_COMPLETE, false).apply()
            startActivity(Intent(this, SetupActivity::class.java))
            finish()
        }

        // Save
        findViewById<MaterialButton>(R.id.btnSave).setOnClickListener {
            val url = urlEdit.text.toString().trim()
            if (url.isEmpty()) {
                Toast.makeText(this, "URL cannot be empty", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            val screensaverOn = switchScreensaver.isChecked
            prefs.edit()
                .putString(KEY_URL, url)
                .putBoolean(KEY_SCREENSAVER, screensaverOn)
                .apply()
            // Screen always stays on in kiosk mode — no FLAG_KEEP_SCREEN_ON change needed here.
            // Push screensaver preference to the webapp so the in-app clock overlay is toggled.
            Thread {
                try {
                    val apiUrl = "$url/api/index.php?action=save_settings"
                    val body   = "{\"screensaver_enabled\":$screensaverOn}"
                    val conn   = (java.net.URL(apiUrl).openConnection() as java.net.HttpURLConnection).apply {
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
            Toast.makeText(this, "Impostazioni salvate", Toast.LENGTH_SHORT).show()
            finish()
        }
    }

    private fun testConnection() {
        val url = urlEdit.text.toString().trim()
        if (url.isEmpty()) {
            Toast.makeText(this, "Enter a URL first", Toast.LENGTH_SHORT).show()
            return
        }

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
                            Toast.makeText(this, "✓ Connection successful!", Toast.LENGTH_SHORT).show()
                        } else {
                            Toast.makeText(this, "⚠ Server responded: $code", Toast.LENGTH_SHORT).show()
                        }
                    }
                }
            } catch (e: Exception) {
                runOnUiThread {
                    Toast.makeText(this, "✗ Cannot reach server", Toast.LENGTH_SHORT).show()
                }
            }
        }.start()
    }
}
