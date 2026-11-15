package it.dadaloop.evershelf.health

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import org.json.JSONObject

/** One stored day. [json] is the full snapshot, [note] is the household's own sentence. */
data class DayRow(
    val date: String,
    val json: String,
    val note: String,
    val pending: Boolean,
    val localOnly: Boolean,
    val updatedAt: Long,
    val syncedAt: Long,
) {
    fun obj(): JSONObject = try { JSONObject(json) } catch (_: Exception) { JSONObject() }
}

/**
 * The phone's copy of the diary.
 *
 * A day is written here BEFORE anything is uploaded, and it stays `pending` until EverShelf
 * acknowledges it: that is what makes "outside the house, no VPN" safe. Nothing in this file
 * is the household's truth — EverShelf keeps that — it is the bridge's queue and its charts.
 */
class HealthDb(context: Context) : SQLiteOpenHelper(context, NAME, null, VERSION) {

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """CREATE TABLE days (
                date TEXT PRIMARY KEY,
                json TEXT NOT NULL,
                note TEXT NOT NULL DEFAULT '',
                pending INTEGER NOT NULL DEFAULT 1,
                local_only INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL,
                synced_at INTEGER NOT NULL DEFAULT 0
            )"""
        )
        db.execSQL(
            """CREATE TABLE sync_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts INTEGER NOT NULL,
                kind TEXT NOT NULL,
                ok INTEGER NOT NULL,
                message TEXT NOT NULL DEFAULT ''
            )"""
        )
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        // Additive only: the diary is cheap to rebuild, but losing a queued day is not.
        if (oldVersion < 2) {
            runCatching { db.execSQL("ALTER TABLE days ADD COLUMN local_only INTEGER NOT NULL DEFAULT 0") }
        }
    }

    /**
     * Writes a day, MERGING with what is already stored: Health Connect answers per metric and
     * a metric it could not read must not erase the value a previous pass had found.
     */
    fun upsertDay(date: String, payload: JSONObject, pending: Boolean, localOnly: Boolean = false) {
        val db = writableDatabase
        val existing = getDay(date)
        val merged = if (existing != null) mergeJson(existing.obj(), payload) else payload
        val now = System.currentTimeMillis()
        val values = ContentValues().apply {
            put("date", date)
            put("json", merged.toString())
            put("updated_at", now)
            if (existing == null) {
                put("pending", if (pending) 1 else 0)
                put("local_only", if (localOnly) 1 else 0)
                put("synced_at", 0)
            } else {
                // A new reading of a day that was already sent makes it pending again.
                if (pending && !existing.pending) put("pending", 1)
                if (localOnly) put("local_only", 1)
            }
        }
        db.insertWithOnConflict(TABLE_DAYS, null, values, SQLiteDatabase.CONFLICT_REPLACE)
    }

    fun setNote(date: String, note: String) {
        val values = ContentValues().apply { put("note", note) }
        writableDatabase.update(TABLE_DAYS, values, "date = ?", arrayOf(date))
    }

    fun markSynced(date: String) {
        val values = ContentValues().apply {
            put("pending", 0)
            put("synced_at", System.currentTimeMillis())
        }
        writableDatabase.update(TABLE_DAYS, values, "date = ?", arrayOf(date))
    }

    fun markPending(date: String) {
        val values = ContentValues().apply { put("pending", 1) }
        writableDatabase.update(TABLE_DAYS, values, "date = ?", arrayOf(date))
    }

    fun getDay(date: String): DayRow? {
        readableDatabase.query(
            TABLE_DAYS, null, "date = ?", arrayOf(date), null, null, null, "1"
        ).use { c ->
            if (!c.moveToFirst()) return null
            return DayRow(
                date = c.getString(c.getColumnIndexOrThrow("date")),
                json = c.getString(c.getColumnIndexOrThrow("json")),
                note = c.getString(c.getColumnIndexOrThrow("note")) ?: "",
                pending = c.getInt(c.getColumnIndexOrThrow("pending")) != 0,
                localOnly = c.getInt(c.getColumnIndexOrThrow("local_only")) != 0,
                updatedAt = c.getLong(c.getColumnIndexOrThrow("updated_at")),
                syncedAt = c.getLong(c.getColumnIndexOrThrow("synced_at")),
            )
        }
    }

    fun recentDays(limit: Int = 400): List<DayRow> {
        val out = ArrayList<DayRow>()
        readableDatabase.query(
            TABLE_DAYS, null, null, null, null, null, "date DESC", limit.toString()
        ).use { c ->
            while (c.moveToNext()) {
                out.add(
                    DayRow(
                        date = c.getString(c.getColumnIndexOrThrow("date")),
                        json = c.getString(c.getColumnIndexOrThrow("json")),
                        note = c.getString(c.getColumnIndexOrThrow("note")) ?: "",
                        pending = c.getInt(c.getColumnIndexOrThrow("pending")) != 0,
                        localOnly = c.getInt(c.getColumnIndexOrThrow("local_only")) != 0,
                        updatedAt = c.getLong(c.getColumnIndexOrThrow("updated_at")),
                        syncedAt = c.getLong(c.getColumnIndexOrThrow("synced_at")),
                    )
                )
            }
        }
        return out
    }

    fun daysSince(fromDate: String): List<DayRow> =
        recentDays(2000).filter { it.date >= fromDate }.sortedBy { it.date }

    fun pendingDays(limit: Int = 31): List<DayRow> {
        val out = ArrayList<DayRow>()
        readableDatabase.query(
            TABLE_DAYS, null, "pending = 1", null, null, null, "date ASC", limit.toString()
        ).use { c ->
            while (c.moveToNext()) {
                out.add(
                    DayRow(
                        date = c.getString(c.getColumnIndexOrThrow("date")),
                        json = c.getString(c.getColumnIndexOrThrow("json")),
                        note = c.getString(c.getColumnIndexOrThrow("note")) ?: "",
                        pending = true,
                        localOnly = c.getInt(c.getColumnIndexOrThrow("local_only")) != 0,
                        updatedAt = c.getLong(c.getColumnIndexOrThrow("updated_at")),
                        syncedAt = c.getLong(c.getColumnIndexOrThrow("synced_at")),
                    )
                )
            }
        }
        return out
    }

    fun pendingCount(): Int {
        readableDatabase.rawQuery("SELECT COUNT(*) FROM $TABLE_DAYS WHERE pending = 1", null).use {
            return if (it.moveToFirst()) it.getInt(0) else 0
        }
    }

    fun dayCount(): Int {
        readableDatabase.rawQuery("SELECT COUNT(*) FROM $TABLE_DAYS", null).use {
            return if (it.moveToFirst()) it.getInt(0) else 0
        }
    }

    fun logSync(kind: String, ok: Boolean, message: String) {
        val values = ContentValues().apply {
            put("ts", System.currentTimeMillis())
            put("kind", kind)
            put("ok", if (ok) 1 else 0)
            put("message", message.take(500))
        }
        writableDatabase.insert(TABLE_LOG, null, values)
        // Keep the log bounded: 300 rows are plenty to explain the last days.
        writableDatabase.execSQL(
            "DELETE FROM $TABLE_LOG WHERE id NOT IN (SELECT id FROM $TABLE_LOG ORDER BY id DESC LIMIT 300)"
        )
    }

    fun recentSyncLog(limit: Int = 20): List<Triple<Long, Boolean, String>> {
        val out = ArrayList<Triple<Long, Boolean, String>>()
        readableDatabase.query(
            TABLE_LOG, arrayOf("ts", "ok", "message"), null, null, null, null, "id DESC", limit.toString()
        ).use { c ->
            while (c.moveToNext()) {
                out.add(Triple(c.getLong(0), c.getInt(1) != 0, c.getString(2) ?: ""))
            }
        }
        return out
    }

    fun deleteAll() {
        writableDatabase.delete(TABLE_DAYS, null, null)
        writableDatabase.delete(TABLE_LOG, null, null)
    }

    private fun mergeJson(old: JSONObject, new: JSONObject): JSONObject {
        val out = JSONObject(old.toString())
        val keys = new.keys()
        while (keys.hasNext()) {
            val k = keys.next()
            val v = new.opt(k)
            if (v == null || v == JSONObject.NULL) continue
            out.put(k, v)
        }
        return out
    }

    companion object {
        private const val NAME = "evershelf_health.db"
        private const val VERSION = 2
        private const val TABLE_DAYS = "days"
        private const val TABLE_LOG = "sync_log"
    }
}
