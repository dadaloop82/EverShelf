package it.dadaloop.evershelf.kiosk

import android.content.Context
import android.content.SharedPreferences
import android.os.Bundle
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton
import java.net.HttpURLConnection
import java.net.URL

class SettingsActivity : AppCompatActivity() {

    private lateinit var prefs: SharedPreferences
    private lateinit var urlEdit: EditText

    companion object {
        private const val PREFS_NAME = "evershelf_kiosk"
        private const val KEY_URL = "evershelf_url"
        private const val KEY_SETUP_COMPLETE = "setup_complete"
        private const val KEY_LAST_DEVICE = "last_device_address"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_settings)

        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        urlEdit = findViewById(R.id.urlEdit)

        // Load saved URL
        urlEdit.setText(prefs.getString(KEY_URL, "") ?: "")

        // Scale status
        val scaleDevice = prefs.getString(KEY_LAST_DEVICE, null)
        findViewById<TextView>(R.id.scaleDeviceInfo).text =
            if (scaleDevice != null) "Last connected: $scaleDevice" else "No scale connected yet"

        // Back button
        findViewById<android.widget.ImageButton>(R.id.btnBack).setOnClickListener {
            finish()
        }

        // Test connection
        findViewById<MaterialButton>(R.id.btnTestConnection).setOnClickListener {
            testConnection()
        }

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
                val conn = URL(testUrl).openConnection() as HttpURLConnection
                conn.connectTimeout = 5000
                conn.readTimeout = 5000
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
            } catch (e: Exception) {
                runOnUiThread {
                    Toast.makeText(this, "✗ Cannot reach server", Toast.LENGTH_SHORT).show()
                }
            }
        }.start()
    }
}
