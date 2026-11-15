package it.dadaloop.evershelf.health

import android.content.Context

/**
 * Everything that belongs to this DEVICE: the pairing, the sync pace and the UI choices.
 *
 * The health data itself lives in [HealthDb] and the household state on the server — this
 * file never stores a metric.
 */
object Prefs {
    private const val NAME = "evershelf_health"

    private const val KEY_URL = "url"
    private const val KEY_TOKEN = "token"
    private const val KEY_LAST_SYNC = "last_sync"
    private const val KEY_LAST_PAYLOAD = "last_payload"
    private const val KEY_AUTO_SYNC = "auto_sync"
    private const val KEY_INTERVAL = "sync_interval_min"
    private const val KEY_WIFI_ONLY = "wifi_only"
    private const val KEY_KEEPALIVE = "keepalive"
    private const val KEY_NOTIFICATIONS = "notifications"
    private const val KEY_AUTO_REPORT = "auto_report"
    private const val KEY_STEPS_GOAL = "steps_goal"
    private const val KEY_KCAL_GOAL = "kcal_goal"
    private const val KEY_BACKFILL_DAYS = "backfill_days"
    private const val KEY_LAST_UPDATE_CHECK = "last_update_check"
    private const val KEY_PENDING_UPDATE = "pending_update_version"

    const val DEFAULT_INTERVAL_MIN = 30
    const val DEFAULT_BACKFILL_DAYS = 30

    fun get(ctx: Context) = ctx.getSharedPreferences(NAME, Context.MODE_PRIVATE)

    fun isConfigured(ctx: Context): Boolean =
        url(ctx).isNotBlank() && token(ctx).isNotBlank()

    fun url(ctx: Context): String = get(ctx).getString(KEY_URL, "") ?: ""
    fun token(ctx: Context): String = get(ctx).getString(KEY_TOKEN, "") ?: ""

    fun lastSync(ctx: Context): Long = get(ctx).getLong(KEY_LAST_SYNC, 0L)
    fun lastPayloadJson(ctx: Context): String = get(ctx).getString(KEY_LAST_PAYLOAD, "") ?: ""

    fun autoSync(ctx: Context): Boolean = get(ctx).getBoolean(KEY_AUTO_SYNC, true)
    fun setAutoSync(ctx: Context, value: Boolean) =
        get(ctx).edit().putBoolean(KEY_AUTO_SYNC, value).apply()

    fun syncIntervalMin(ctx: Context): Int {
        val v = get(ctx).getInt(KEY_INTERVAL, DEFAULT_INTERVAL_MIN)
        return v.coerceIn(15, 240)
    }

    fun setSyncIntervalMin(ctx: Context, minutes: Int) =
        get(ctx).edit().putInt(KEY_INTERVAL, minutes.coerceIn(15, 240)).apply()

    fun wifiOnly(ctx: Context): Boolean = get(ctx).getBoolean(KEY_WIFI_ONLY, false)
    fun setWifiOnly(ctx: Context, value: Boolean) =
        get(ctx).edit().putBoolean(KEY_WIFI_ONLY, value).apply()

    fun keepAlive(ctx: Context): Boolean = get(ctx).getBoolean(KEY_KEEPALIVE, true)
    fun setKeepAlive(ctx: Context, value: Boolean) =
        get(ctx).edit().putBoolean(KEY_KEEPALIVE, value).apply()

    fun notifications(ctx: Context): Boolean = get(ctx).getBoolean(KEY_NOTIFICATIONS, true)
    fun setNotifications(ctx: Context, value: Boolean) =
        get(ctx).edit().putBoolean(KEY_NOTIFICATIONS, value).apply()

    fun autoReport(ctx: Context): Boolean = get(ctx).getBoolean(KEY_AUTO_REPORT, true)
    fun setAutoReport(ctx: Context, value: Boolean) =
        get(ctx).edit().putBoolean(KEY_AUTO_REPORT, value).apply()

    fun stepsGoal(ctx: Context): Int = get(ctx).getInt(KEY_STEPS_GOAL, 8000).coerceIn(1000, 50000)
    fun setStepsGoal(ctx: Context, value: Int) =
        get(ctx).edit().putInt(KEY_STEPS_GOAL, value.coerceIn(1000, 50000)).apply()

    fun kcalGoal(ctx: Context): Int = get(ctx).getInt(KEY_KCAL_GOAL, 700).coerceIn(100, 5000)
    fun setKcalGoal(ctx: Context, value: Int) =
        get(ctx).edit().putInt(KEY_KCAL_GOAL, value.coerceIn(100, 5000)).apply()

    fun backfillDays(ctx: Context): Int =
        get(ctx).getInt(KEY_BACKFILL_DAYS, DEFAULT_BACKFILL_DAYS).coerceIn(7, 90)

    fun lastUpdateCheck(ctx: Context): Long = get(ctx).getLong(KEY_LAST_UPDATE_CHECK, 0L)
    fun setLastUpdateCheck(ctx: Context, ts: Long) =
        get(ctx).edit().putLong(KEY_LAST_UPDATE_CHECK, ts).apply()

    fun pendingUpdateVersion(ctx: Context): String =
        get(ctx).getString(KEY_PENDING_UPDATE, "") ?: ""

    fun setPendingUpdateVersion(ctx: Context, v: String) =
        get(ctx).edit().putString(KEY_PENDING_UPDATE, v).apply()

    fun saveServer(ctx: Context, url: String, token: String) {
        get(ctx).edit()
            .putString(KEY_URL, normalizeBaseUrl(url))
            .putString(KEY_TOKEN, token.trim())
            .apply()
    }

    fun saveLastSync(ctx: Context, payloadJson: String) {
        get(ctx).edit()
            .putLong(KEY_LAST_SYNC, System.currentTimeMillis())
            .putString(KEY_LAST_PAYLOAD, payloadJson)
            .apply()
    }

    fun clearPairing(ctx: Context) {
        get(ctx).edit().remove(KEY_URL).remove(KEY_TOKEN).remove(KEY_LAST_SYNC).apply()
    }

    fun clear(ctx: Context) {
        get(ctx).edit().clear().apply()
    }

    /** Adds the missing scheme and the trailing slash so every call builds the same URL. */
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
