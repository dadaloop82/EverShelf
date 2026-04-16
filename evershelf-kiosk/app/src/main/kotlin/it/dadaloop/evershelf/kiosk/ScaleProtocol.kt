package it.dadaloop.evershelf.kiosk

import android.bluetooth.BluetoothGattCharacteristic
import java.util.UUID

data class WeightReading(
    val value: Float,
    val unit: String,
    val stable: Boolean,
)

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

object ScaleProtocol {
    private const val MAX_GRAMS = 15000f
    private const val MIN_GRAMS = 0.5f

    fun resetState() {}

    fun parse(
        char: BluetoothGattCharacteristic,
        data: ByteArray,
        debug: ((String) -> Unit)? = null,
    ): WeightReading? {
        if (data.size < 2) return null

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
        val flags = data[0].toInt() and 0xFF
        val isImperial = (flags and 0x01) != 0
        val raw = u16le(data, 1)
        return if (isImperial) {
            val lb = raw * 0.01f
            if (lb < 0.01f || lb > 33f) null else WeightReading(lb, "lb", stable = true)
        } else {
            val g = raw * 5f
            if (g < MIN_GRAMS || g > MAX_GRAMS) null else WeightReading(g, "g", stable = true)
        }
    }

    private fun parseQNFood(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        val calc = data.take(17).sumOf { it.toInt() and 0xFF } and 0xFF
        if (calc != (data[17].toInt() and 0xFF)) return null
        val rawValue = u16be(data, 9)
        val stable = (data[8].toInt() and 0x08) != 0
        val unit = when (data[4].toInt() and 0xFF) {
            0x01 -> "g"; 0x02 -> "oz"; 0x03 -> "ml"; 0x04 -> "ml"; else -> "g"
        }
        val value = rawValue / 10f
        if (rawValue == 0) return null
        val valueG = if (unit == "oz") value * 28.3495f else value
        if (valueG < MIN_GRAMS || valueG > MAX_GRAMS) return null
        return WeightReading(value, unit, stable)
    }

    private fun parseGeneric(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 3) return null
        data class C(val pos: Int, val be: Boolean, val div: Float, val label: String)
        val candidates = listOf(
            C(1, false, 1f, "p1LEg"), C(1, true, 1f, "p1BEg"),
            C(2, false, 1f, "p2LEg"), C(2, true, 1f, "p2BEg"),
            C(3, false, 1f, "p3LEg"), C(3, true, 1f, "p3BEg"),
            C(1, false, 10f, "p1LE.1g"), C(1, true, 10f, "p1BE.1g"),
            C(2, false, 10f, "p2LE.1g"), C(2, true, 10f, "p2BE.1g"),
            C(3, false, 10f, "p3LE.1g"), C(3, true, 10f, "p3BE.1g"),
            C(1, false, 2f, "p1LE.5g"), C(1, true, 2f, "p1BE.5g"),
            C(1, false, 0.1f, "p1LEcg"), C(1, true, 0.1f, "p1BEcg"),
            C(3, false, 0.1f, "p3LEcg"), C(3, true, 0.1f, "p3BEcg"),
        )
        for (c in candidates) {
            if (c.pos + 1 >= data.size) continue
            val raw = if (c.be) u16be(data, c.pos) else u16le(data, c.pos)
            if (raw == 0) continue
            val g = raw / c.div
            if (g in MIN_GRAMS..MAX_GRAMS) return WeightReading(g, "g", stable = false)
        }
        return null
    }

    private fun u16le(b: ByteArray, off: Int): Int =
        (b[off].toInt() and 0xFF) or ((b[off + 1].toInt() and 0xFF) shl 8)
    private fun u16be(b: ByteArray, off: Int): Int =
        ((b[off].toInt() and 0xFF) shl 8) or (b[off + 1].toInt() and 0xFF)
}
