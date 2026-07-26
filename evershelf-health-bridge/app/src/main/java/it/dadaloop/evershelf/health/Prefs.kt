package it.dadaloop.evershelf.health

import android.content.Context

object Prefs {
    private const val NAME = "evershelf_health"

    fun get(ctx: Context) = ctx.getSharedPreferences(NAME, Context.MODE_PRIVATE)

    fun isConfigured(ctx: Context): Boolean {
        val p = get(ctx)
        return !p.getString("url", null).isNullOrBlank() && !p.getString("token", null).isNullOrBlank()
    }

    fun url(ctx: Context): String = get(ctx).getString("url", "") ?: ""
    fun token(ctx: Context): String = get(ctx).getString("token", "") ?: ""
    fun lastSync(ctx: Context): Long = get(ctx).getLong("last_sync", 0L)
    fun lastPayloadJson(ctx: Context): String = get(ctx).getString("last_payload", "") ?: ""
    fun autoSync(ctx: Context): Boolean = get(ctx).getBoolean("auto_sync", true)

    fun saveServer(ctx: Context, url: String, token: String) {
        get(ctx).edit()
            .putString("url", normalizeBaseUrl(url))
            .putString("token", token.trim())
            .apply()
    }

    fun saveLastSync(ctx: Context, payloadJson: String) {
        get(ctx).edit()
            .putLong("last_sync", System.currentTimeMillis())
            .putString("last_payload", payloadJson)
            .apply()
    }

    fun clear(ctx: Context) {
        get(ctx).edit().clear().apply()
    }

    fun normalizeBaseUrl(raw: String): String {
        var u = raw.trim()
        if (u.isEmpty()) return u
        if (!u.startsWith("http://") && !u.startsWith("https://")) {
            u = "https://$u"
        }
        if (!u.endsWith("/")) u += "/"
        return u
    }
}
