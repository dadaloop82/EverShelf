package it.dadaloop.evershelf.kiosk

import android.content.Context
import android.content.Intent
import android.content.SharedPreferences
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton
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
        private const val GATEWAY_PACKAGE = "it.dadaloop.evershelf.scalegate"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_settings)

        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        urlEdit = findViewById(R.id.urlEdit)

        urlEdit.setText(prefs.getString(KEY_URL, "") ?: "")

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

        // Back
        findViewById<android.widget.ImageButton>(R.id.btnBack).setOnClickListener { finish() }

        // Test connection
        findViewById<MaterialButton>(R.id.btnTestConnection).setOnClickListener { testConnection() }

        // Run wizard again
        findViewById<MaterialButton>(R.id.btnRunWizard).setOnClickListener {
            prefs.edit().putBoolean(KEY_SETUP_COMPLETE, false).apply()
            Toast.makeText(this, "Wizard will run on next launch", Toast.LENGTH_SHORT).show()
            finish()
        }

        // Save
        findViewById<MaterialButton>(R.id.btnSave).setOnClickListener {
            val url = urlEdit.text.toString().trim()
            if (url.isEmpty()) {
                Toast.makeText(this, "URL cannot be empty", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            prefs.edit().putString(KEY_URL, url).apply()
            Toast.makeText(this, "Settings saved", Toast.LENGTH_SHORT).show()
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
                val testUrl = if (url.endsWith("/")) "${url}api/" else "${url}/api/"
                val conn = URL(testUrl).openConnection()

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
