package it.dadaloop.evershelf.scalegate

import android.bluetooth.BluetoothGattCharacteristic
import java.util.UUID

// --- Data model ---

/**
 * A single weight reading from a BLE scale.
 * [value] is in the scale's current display unit (grams, oz, ml, lb).
 * [unit]  is "g", "oz", "ml", or "lb".
 */
data class WeightReading(
    val value: Float,
    val unit: String,
    val stable: Boolean,
)

// --- UUIDs ---

val CCCD_UUID: UUID = UUID.fromString("00002902-0000-1000-8000-00805f9b34fb")

object BleUuids {
    // BLE SIG Weight Scale (some kitchen scales use this)
    val WEIGHT_SCALE_SERVICE    = UUID.fromString("0000181d-0000-1000-8000-00805f9b34fb")
    val WEIGHT_MEASUREMENT_CHAR = UUID.fromString("00002a9d-0000-1000-8000-00805f9b34fb")

    // Battery
    val BATTERY_SERVICE    = UUID.fromString("0000180f-0000-1000-8000-00805f9b34fb")
    val BATTERY_LEVEL_CHAR = UUID.fromString("00002a19-0000-1000-8000-00805f9b34fb")

    // Common vendor services used by kitchen scales
    val FFE0 = UUID.fromString("0000ffe0-0000-1000-8000-00805f9b34fb")
    val FFE1 = UUID.fromString("0000ffe1-0000-1000-8000-00805f9b34fb")
    val FFF0 = UUID.fromString("0000fff0-0000-1000-8000-00805f9b34fb")
    val FFF1 = UUID.fromString("0000fff1-0000-1000-8000-00805f9b34fb")
    val FFF4 = UUID.fromString("0000fff4-0000-1000-8000-00805f9b34fb")

    // Acaia / Brewista coffee scales
    val ACAIA_SERVICE = UUID.fromString("49535343-fe7d-4ae5-8fa9-9fafd205e455")
    val ACAIA_CHAR    = UUID.fromString("49535343-8841-43f4-a8d4-ecbe34729bb3")

    // QN/Yolanda food scale secondary service (QN-KS, etc.)
    val QN_AE00 = UUID.fromString("0000ae00-0000-1000-8000-00805f9b34fb")
    val QN_AE02 = UUID.fromString("0000ae02-0000-1000-8000-00805f9b34fb")
}

// --- Food scale protocol parser ---

object ScaleProtocol {

    // Plausible kitchen scale range
    private const val MAX_GRAMS = 15000f
    private const val MIN_GRAMS = 0.5f  // allow tare/small values

    fun resetState() { /* reserved for future use */ }

    fun parse(
        char: BluetoothGattCharacteristic,
        data: ByteArray,
        debug: ((String) -> Unit)? = null,
    ): WeightReading? {
        if (data.size < 2) {
            debug?.invoke("skip: packet too short (" + data.size + "B)")
            return null
        }

        // UUID-specific parsers
        when (char.uuid) {
            BleUuids.WEIGHT_MEASUREMENT_CHAR -> return parseSigWeight(data, debug)
        }

        // QN/Yolanda food scale (QN-KS, BC-KS, etc.):
        //   18-byte frame starting with 0x10 0x12 on FFF1
        if (data.size == 18
            && (data[0].toInt() and 0xFF) == 0x10
            && (data[1].toInt() and 0xFF) == 0x12) {
            return parseQNFood(data, debug)
        }

        return parseGeneric(data, debug)
    }

    // -------------------------------------------------------------------------
    // BLE SIG 0x2A9D Weight Measurement
    // -------------------------------------------------------------------------
    private fun parseSigWeight(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 3) return null
        val flags     = data[0].toInt() and 0xFF
        val isImperial = (flags and 0x01) != 0
        val raw       = u16le(data, 1)

        return if (isImperial) {
            val lb = raw * 0.01f
            debug?.invoke("SIG 2A9D: raw=$raw -> ${lb}lb")
            if (lb < 0.01f || lb > 33f) null
            else WeightReading(lb, "lb", stable = true)
        } else {
            val g = raw * 5f   // 0.005 kg resolution = 5 g/unit
            debug?.invoke("SIG 2A9D: raw=$raw -> ${g}g")
            if (g < MIN_GRAMS || g > MAX_GRAMS) null
            else WeightReading(g, "g", stable = true)
        }
    }

    // -------------------------------------------------------------------------
    // QN / Yolanda food scale  (QN-KS, BC-KS, YolandaKS, ...)
    //
    // 18-byte notification on service 0xFFF0, char 0xFFF1:
    //   [0x10][0x12][00][??][unit][02][05][01][flags][w_hi][w_lo][7E][1F][02][58][02][01][crc]
    //   index:  0     1   2   3    4   5   6   7      8     9    10   11  12  13  14  15  16   17
    //
    //   weight  = u16BE(data, 9) / 10.0   (0.1-unit resolution)
    //   unit    = byte[4]: 0x01=g, 0x02=oz, 0x03=ml(water), 0x04=ml(milk)
    //   stable  = bit3 of byte[8] != 0   (0xF8=stable, 0xF0=settling)
    //   crc     = sum(bytes[0..16]) mod 256
    // -------------------------------------------------------------------------
    private fun parseQNFood(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        // Verify checksum
        val calc = data.take(17).sumOf { it.toInt() and 0xFF } and 0xFF
        if (calc != (data[17].toInt() and 0xFF)) {
            debug?.invoke("QN-KS: CRC mismatch (calc=0x%02X got=0x%02X)".format(calc, data[17].toInt() and 0xFF))
            return null
        }

        val rawValue = u16be(data, 9)
        val stable   = (data[8].toInt() and 0x08) != 0
        val unit     = when (data[4].toInt() and 0xFF) {
            0x01 -> "g"
            0x02 -> "oz"
            0x03 -> "ml"  // water mode
            0x04 -> "ml"  // milk mode
            else -> "g"
        }

        // Resolution is 0.1 unit (e.g. 170 raw = 17.0 g, 195 raw = 19.5 g)
        val value = rawValue / 10f

        debug?.invoke("QN-KS: ${value}${unit} stable=$stable (raw=$rawValue unit_byte=0x%02X)".format(data[4].toInt() and 0xFF))

        if (rawValue == 0) return null
        // Convert to grams for range check
        val valueG = if (unit == "oz") value * 28.3495f else value
        if (valueG < MIN_GRAMS || valueG > MAX_GRAMS) return null

        return WeightReading(value, unit, stable)
    }

    // -------------------------------------------------------------------------
    // Generic fallback parser
    // Tries common frame layouts used by many BLE kitchen scales.
    // -------------------------------------------------------------------------
    private fun parseGeneric(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 3) {
            debug?.invoke("generic: skip short packet (" + data.size + "B)")
            return null
        }

        data class C(val pos: Int, val be: Boolean, val div: Float, val label: String)

        val candidates = listOf(
            // Direct grams (1g resolution)
            C(1, false, 1f,     "pos1 LE g"),
            C(1, true,  1f,     "pos1 BE g"),
            C(2, false, 1f,     "pos2 LE g"),
            C(2, true,  1f,     "pos2 BE g"),
            C(3, false, 1f,     "pos3 LE g"),
            C(3, true,  1f,     "pos3 BE g"),
            // 0.1g resolution (high-precision scales)
            C(1, false, 10f,    "pos1 LE 0.1g"),
            C(1, true,  10f,    "pos1 BE 0.1g"),
            C(2, false, 10f,    "pos2 LE 0.1g"),
            C(2, true,  10f,    "pos2 BE 0.1g"),
            C(3, false, 10f,    "pos3 LE 0.1g"),
            C(3, true,  10f,    "pos3 BE 0.1g"),
            // 0.5g resolution
            C(1, false, 2f,     "pos1 LE 0.5g"),
            C(1, true,  2f,     "pos1 BE 0.5g"),
            // Raw = centgrams (raw*10 = g)
            C(1, false, 0.1f,   "pos1 LE cg"),
            C(1, true,  0.1f,   "pos1 BE cg"),
            C(3, false, 0.1f,   "pos3 LE cg"),
            C(3, true,  0.1f,   "pos3 BE cg"),
        )

        for (c in candidates) {
            if (c.pos + 1 >= data.size) continue
            val raw = if (c.be) u16be(data, c.pos) else u16le(data, c.pos)
            if (raw == 0) continue
            val g = raw / c.div
            if (g in MIN_GRAMS..MAX_GRAMS) {
                debug?.invoke("generic [${c.label}]: raw=$raw -> ${g}g (unstable)")
                return WeightReading(g, "g", stable = false)
            }
        }
        debug?.invoke("generic: no valid candidate in " + data.size + " bytes")
        return null
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    private fun u16le(b: ByteArray, off: Int): Int =
        (b[off].toInt() and 0xFF) or ((b[off + 1].toInt() and 0xFF) shl 8)

    private fun u16be(b: ByteArray, off: Int): Int =
        ((b[off].toInt() and 0xFF) shl 8) or (b[off + 1].toInt() and 0xFF)
}
