package it.dadaloop.evershelf.health

import android.content.Context
import android.content.res.Configuration
import java.util.Locale

object LocaleHelper {
    private const val KEY = "app_language"

    fun language(ctx: Context): String =
        Prefs.get(ctx).getString(KEY, "") ?: ""

    fun setLanguage(ctx: Context, lang: String) {
        Prefs.get(ctx).edit().putString(KEY, lang).apply()
    }

    fun wrap(base: Context): Context {
        val lang = language(base)
        if (lang.isBlank()) return base
        val locale = Locale(lang)
        Locale.setDefault(locale)
        val config = Configuration(base.resources.configuration)
        config.setLocale(locale)
        return base.createConfigurationContext(config)
    }
}
