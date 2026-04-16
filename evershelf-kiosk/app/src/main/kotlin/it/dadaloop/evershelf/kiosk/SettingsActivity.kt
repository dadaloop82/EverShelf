package it.dadaloop.evershelf.kiosk

import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import it.dadaloop.evershelf.kiosk.databinding.ActivitySettingsBinding

private const val PREFS_NAME = "evershelf_kiosk"
private const val PREF_URL = "evershelf_url"
private const val DEFAULT_URL = "http://evershelf.local"

class SettingsActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySettingsBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE)
        binding.editUrl.setText(prefs.getString(PREF_URL, DEFAULT_URL))

        binding.btnSave.setOnClickListener {
            val url = binding.editUrl.text.toString().trim()
            if (url.isEmpty()) {
                Toast.makeText(this, "URL cannot be empty", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            prefs.edit().putString(PREF_URL, url).apply()
            Toast.makeText(this, "Saved! Returning to kiosk...", Toast.LENGTH_SHORT).show()
            finish()
        }

        binding.btnBack.setOnClickListener {
            finish()
        }
    }
}
