package it.dadaloop.evershelf.kiosk.scale

import android.bluetooth.BluetoothGattCharacteristic
import java.util.UUID

// ── Data model ────────────────────────────────────────────────────────────────

data class WeightReading(
    val value: Float,
    val unit: String,
    val stable: Boolean,
)

// ── UUIDs ─────────────────────────────────────────────────────────────────────

val CCCD_UUID: UUID = UUID.fromString("00002902-0000-1000-8000-00805f9b34fb")

object BleUuids {
    val WEIGHT_SCALE_SERVICE    = UUID.fromString("0000181d-0000-1000-8000-00805f9b34fb")
    val WEIGHT_MEASUREMENT_CHAR = UUID.fromString("00002a9d-0000-1000-8000-00805f9b34fb")
    val BATTERY_SERVICE    = UUID.fromString("0000180f-0000-1000-8000-00805f9b34fb")
    val BATTERY_LEVEL_CHAR = UUID.fromString("00002a19-0000-1000-8000-00805f9b34fb")
    val FFE0 = UUID.fromString("0000ffe0-0000-1000-8000-00805f9b34fb")
    val FFE1 = UUID.fromString("0000ffe1-0000-1000-8000-00805f9b34fb")
    val FFF0 = UUID.fromString("0000fff0-0000-1000-8000-00805f9b34fb")
    val FFF1 = UUID.fromString("0000fff1-0000-1000-8000-00805f9b34fb")
    val FFF4 = UUID.fromString("0000fff4-0000-1000-8000-00805f9b34fb")
    val ACAIA_SERVICE = UUID.fromString("49535343-fe7d-4ae5-8fa9-9fafd205e455")
    val ACAIA_CHAR    = UUID.fromString("49535343-8841-43f4-a8d4-ecbe34729bb3")
    val QN_AE00 = UUID.fromString("0000ae00-0000-1000-8000-00805f9b34fb")
    val QN_AE02 = UUID.fromString("0000ae02-0000-1000-8000-00805f9b34fb")
}

// ── Scale protocol parser ─────────────────────────────────────────────────────

object ScaleProtocol {

    private const val MAX_GRAMS = 15000f
    private const val MIN_GRAMS = 0.5f

    fun resetState() { /* reserved */ }

    fun parse(
        char: BluetoothGattCharacteristic,
        data: ByteArray,
        debug: ((String) -> Unit)? = null,
    ): WeightReading? {
        if (data.size < 2) {
            debug?.invoke("skip: packet too short (${data.size}B)")
            return null
        }
        when (char.uuid) {
            BleUuids.WEIGHT_MEASUREMENT_CHAR -> return parseSigWeight(data, debug)
        }
        if (data.size == 18
            && (data[0].toInt() and 0xFF) == 0x10
            && (data[1].toInt() and 0xFF) == 0x12) {
            return parseQNFood(data, debug)
        }
        return parseGeneric(data, debug)
    }

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
            val g = raw * 5f
            debug?.invoke("SIG 2A9D: raw=$raw -> ${g}g")
            if (g < MIN_GRAMS || g > MAX_GRAMS) null
            else WeightReading(g, "g", stable = true)
        }
    }

    private fun parseQNFood(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        val calc = data.take(17).sumOf { it.toInt() and 0xFF } and 0xFF
        if (calc != (data[17].toInt() and 0xFF)) {
            debug?.invoke("QN-KS: CRC mismatch")
            return null
        }
        val rawValue = u16be(data, 9)
        val stable   = (data[8].toInt() and 0x08) != 0
        val unit     = when (data[4].toInt() and 0xFF) {
            0x01 -> "g"; 0x02 -> "oz"; 0x03 -> "ml"; 0x04 -> "ml"; else -> "g"
        }
        val value = rawValue / 10f
        debug?.invoke("QN-KS: ${value}${unit} stable=$stable")
        if (rawValue == 0) return null
        val valueG = if (unit == "oz") value * 28.3495f else value
        if (valueG < MIN_GRAMS || valueG > MAX_GRAMS) return null
        return WeightReading(value, unit, stable)
    }

    private fun parseGeneric(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 3) return null
        data class C(val pos: Int, val be: Boolean, val div: Float, val label: String)
        val candidates = listOf(
            C(1, false, 1f, "pos1 LE g"), C(1, true, 1f, "pos1 BE g"),
            C(2, false, 1f, "pos2 LE g"), C(2, true, 1f, "pos2 BE g"),
            C(3, false, 1f, "pos3 LE g"), C(3, true, 1f, "pos3 BE g"),
            C(1, false, 10f, "pos1 LE 0.1g"), C(1, true, 10f, "pos1 BE 0.1g"),
            C(2, false, 10f, "pos2 LE 0.1g"), C(2, true, 10f, "pos2 BE 0.1g"),
            C(3, false, 10f, "pos3 LE 0.1g"), C(3, true, 10f, "pos3 BE 0.1g"),
            C(1, false, 2f, "pos1 LE 0.5g"), C(1, true, 2f, "pos1 BE 0.5g"),
            C(1, false, 0.1f, "pos1 LE cg"), C(1, true, 0.1f, "pos1 BE cg"),
        )
        for (c in candidates) {
            if (c.pos + 1 >= data.size) continue
            val raw = if (c.be) u16be(data, c.pos) else u16le(data, c.pos)
            if (raw == 0) continue
            val g = raw / c.div
            if (g in MIN_GRAMS..MAX_GRAMS) {
                debug?.invoke("generic [${c.label}]: raw=$raw -> ${g}g")
                return WeightReading(g, "g", stable = false)
            }
        }
        return null
    }

    private fun u16le(data: ByteArray, offset: Int) =
        (data[offset].toInt() and 0xFF) or ((data[offset + 1].toInt() and 0xFF) shl 8)

    private fun u16be(data: ByteArray, offset: Int) =
        ((data[offset].toInt() and 0xFF) shl 8) or (data[offset + 1].toInt() and 0xFF)
}
