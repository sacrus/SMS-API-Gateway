package pt.smsapi.gateway.data

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import kotlinx.coroutines.flow.MutableSharedFlow

class LocalDb(context: Context) :
    SQLiteOpenHelper(context.applicationContext, DB_NAME, null, DB_VERSION) {

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """
            CREATE TABLE activity (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                ts        INTEGER NOT NULL,
                level     TEXT    NOT NULL,
                phone     TEXT,
                body      TEXT,
                remote_id INTEGER,
                detail    TEXT
            )
            """.trimIndent()
        )
        db.execSQL("CREATE INDEX idx_activity_ts ON activity (ts DESC)")
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        db.execSQL("DROP TABLE IF EXISTS activity")
        onCreate(db)
    }

    @Synchronized
    fun add(
        level: String,
        detail: String?,
        phone: String? = null,
        body: String? = null,
        remoteId: Long? = null
    ) {
        val values = ContentValues().apply {
            put("ts", System.currentTimeMillis())
            put("level", level)
            put("detail", detail)
            put("phone", phone)
            put("body", body?.take(MAX_BODY_CHARS))
            if (remoteId != null) put("remote_id", remoteId)
        }
        writableDatabase.insert("activity", null, values)
        prune()
        changes.tryEmit(Unit)
    }

    @Synchronized
    fun recent(limit: Int = 200): List<LogEntry> {
        val out = ArrayList<LogEntry>()
        readableDatabase.rawQuery(
            "SELECT id, ts, level, phone, body, remote_id, detail FROM activity ORDER BY id DESC LIMIT ?",
            arrayOf(limit.toString())
        ).use { cursor ->
            while (cursor.moveToNext()) {
                out += LogEntry(
                    id = cursor.getLong(0),
                    timestamp = cursor.getLong(1),
                    level = cursor.getString(2) ?: Level.INFO,
                    phone = if (cursor.isNull(3)) null else cursor.getString(3),
                    body = if (cursor.isNull(4)) null else cursor.getString(4),
                    remoteId = if (cursor.isNull(5)) null else cursor.getLong(5),
                    detail = if (cursor.isNull(6)) null else cursor.getString(6)
                )
            }
        }
        return out
    }

    @Synchronized
    fun clear() {
        writableDatabase.delete("activity", null, null)
        changes.tryEmit(Unit)
    }

    @Synchronized
    private fun prune() {
        writableDatabase.execSQL(
            "DELETE FROM activity WHERE id NOT IN (SELECT id FROM activity ORDER BY id DESC LIMIT ?)",
            arrayOf<Any>(MAX_ROWS.toString())
        )
    }

    companion object {
        private const val DB_NAME = "smsapi_local.db"
        private const val DB_VERSION = 1
        private const val MAX_ROWS = 500
        private const val MAX_BODY_CHARS = 160

        val changes = MutableSharedFlow<Unit>(replay = 0, extraBufferCapacity = 8)
    }
}