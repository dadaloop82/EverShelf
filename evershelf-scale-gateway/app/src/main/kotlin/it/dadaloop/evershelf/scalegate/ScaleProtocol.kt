package it.dadaloop.evershelf.scalegate

import android.bluetooth.BluetoothGattCharacteristic
import java.util.UUID

/**
 * Data model for a single weight reading from a BLE scale.
 */
data class WeightReading(
    val weightKg: Float,
    val stable: Boolean,
    val battery: Int? = null,
    val fatPct: Float? = null,
    val bmi: Float? = null,
    val muscle: Float? = null,
    val water: Float? = null,
    val bone: Float? = null,
    val kcal: Int? = null,
    val impedance: Float? = null,
)

/**
 * Descriptor UUID for enabling BLE notifications (standard 0x2902).
 */
val CCCD_UUID: UUID = UUID.fromString("00002902-0000-1000-8000-00805f9b34fb")

/**
 * Bluetooth SIG standard and common vendor service/characteristic UUIDs.
 */
object BleUuids {
    val WEIGHT_SCALE_SERVICE = UUID.fromString("0000181d-0000-1000-8000-00805f9b34fb")
    val WEIGHT_MEASUREMENT_CHAR = UUID.fromString("00002a9d-0000-1000-8000-00805f9b34fb")

    val BODY_COMPOSITION_SERVICE = UUID.fromString("0000181b-0000-1000-8000-00805f9b34fb")
    val BODY_COMPOSITION_CHAR = UUID.fromString("00002a9c-0000-1000-8000-00805f9b34fb")

    val BATTERY_SERVICE = UUID.fromString("0000180f-0000-1000-8000-00805f9b34fb")
    val BATTERY_LEVEL_CHAR = UUID.fromString("00002a19-0000-1000-8000-00805f9b34fb")

    val QN_SERVICE_FFE0 = UUID.fromString("0000ffe0-0000-1000-8000-00805f9b34fb")
    val QN_NOTIFY_FFE1 = UUID.fromString("0000ffe1-0000-1000-8000-00805f9b34fb")
    val QN_WRITE_FFE3 = UUID.fromString("0000ffe3-0000-1000-8000-00805f9b34fb")
    val QN_WRITE_FFE4 = UUID.fromString("0000ffe4-0000-1000-8000-00805f9b34fb")

    val CUSTOM_FFF0 = UUID.fromString("0000fff0-0000-1000-8000-00805f9b34fb")
    val CUSTOM_FFF1 = UUID.fromString("0000fff1-0000-1000-8000-00805f9b34fb")
    val CUSTOM_FFF2 = UUID.fromString("0000fff2-0000-1000-8000-00805f9b34fb")
    val CUSTOM_FFF4 = UUID.fromString("0000fff4-0000-1000-8000-00805f9b34fb")
}

/**
 * Protocol-aware parser for BLE scale data.
 */
object ScaleProtocol {

    @Volatile
    private var qnWeightDivisor: Float = 100f

    fun resetState() {
        qnWeightDivisor = 100f
    }

    fun parse(
        char: BluetoothGattCharacteristic,
        data: ByteArray,
        debug: ((String) -> Unit)? = null,
    ): WeightReading? {
        when (char.uuid) {
            BleUuids.WEIGHT_MEASUREMENT_CHAR -> {
                if (data.isNotEmpty() && data[0] == 0x2E.toByte()) {
                    return parseRenpho(data, debug)
                }
                return parseWeightMeasurement(data, debug)
            }
            BleUuids.BODY_COMPOSITION_CHAR -> return parseBodyComposition(data, debug)
        }

        if (data.isEmpty()) return null
        val opcode = data[0].toInt() and 0xFF

        if (opcode == 0x10 && data.size >= 6) return parseQNWeight(data, debug)
        if (opcode == 0x12 && data.size > 2) {
            handleQNInfo(data, debug)
            return null
        }
        if (opcode in listOf(0x14, 0x20, 0x21, 0xA1, 0xA3)) {
            val hex = "%02X".format(opcode)
            debug?.invoke("QN ack/handshake opcode=0x$hex")
            return null
        }

        if (opcode == 0xCF && data.size >= 11) return parse1byone(data, debug)
        if (opcode == 0x2E && data.size >= 3) return parseRenpho(data, debug)

        if (data.size == 20) {
            val result = parseHesley(data, debug)
            if (result != null) return result
        }

        return parseGenericSafe(data, debug)
    }

    // --- Bluetooth SIG 0x2A9D ---

    fun parseWeightMeasurement(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 3) return null
        val flags = data[0].toInt() and 0xFF
        val isImperial = (flags and 0x01) != 0
        val rawWeight = u16le(data, 1)

        val weightKg = if (isImperial) {
            rawWeight * 0.01f / 2.20462f
        } else {
            rawWeight * 0.005f
        }

        var bmi: Float? = null
        var offset = 3
        if ((flags and 0x02) != 0) offset += 7
        if ((flags and 0x04) != 0) offset += 1
        if ((flags and 0x08) != 0 && data.size >= offset + 4) {
            val rawBmi = u16le(data, offset)
            bmi = rawBmi * 0.1f
        }

        val unit = if (isImperial) "lb" else "kg"
        val wStr = "%.2f".format(weightKg)
        debug?.invoke("SIG 2A9D: raw=$rawWeight imp=$isImperial -> ${wStr}kg ($unit)")
        if (weightKg in 0.1f..500f) return WeightReading(weightKg, stable = true, bmi = bmi)
        return null
    }

    // --- Bluetooth SIG 0x2A9C ---

    fun parseBodyComposition(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 4) return null
        val flags = u16le(data, 0)
        val isImperial = (flags and 0x0001) != 0

        val rawFat = u16le(data, 2)
        val fatPct = rawFat * 0.1f

        var offset = 4
        if ((flags and 0x0002) != 0) offset += 7
        if ((flags and 0x0200) != 0) offset += 1

        var weightKg: Float? = null
        if ((flags and 0x0080) != 0 && data.size >= offset + 2) {
            val rawW = u16le(data, offset)
            weightKg = if (isImperial) rawW * 0.01f / 2.20462f else rawW * 0.005f
            offset += 2
        }

        if (weightKg == null || weightKg <= 0f) return null

        val wStr = "%.2f".format(weightKg)
        val fStr = "%.1f".format(fatPct)
        debug?.invoke("SIG 2A9C: weight=${wStr}kg fat=${fStr}%")
        return WeightReading(
            weightKg = weightKg,
            stable = true,
            fatPct = if (fatPct > 0f) fatPct else null,
        )
    }

    // --- QN / Yolanda / FITINDEX ---

    fun parseQNWeight(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 6) return null

        val byte4 = data[4].toInt() and 0xFF
        val isES30M = byte4 <= 0x02 && qnWeightDivisor == 10f

        val stable: Boolean
        val raw: Int

        if (isES30M && data.size >= 7) {
            stable = byte4 == 0x02 || byte4 == 0x01
            raw = u16be(data, 5)
        } else {
            raw = u16be(data, 3)
            stable = data.size > 5 && data[5].toInt() == 1
        }

        var weightKg = raw / qnWeightDivisor
        if (weightKg < 1f || weightKg > 300f) {
            val alt = if (qnWeightDivisor == 100f) 10f else 100f
            val altW = raw / alt
            if (altW in 1f..300f) {
                weightKg = altW
                debug?.invoke("QN: auto-adjusted divisor $qnWeightDivisor -> $alt")
            }
        }

        val wStr = "%.2f".format(weightKg)
        debug?.invoke("QN 0x10: raw=$raw div=$qnWeightDivisor -> ${wStr}kg stable=$stable")
        if (weightKg in 0.1f..300f) return WeightReading(weightKg, stable)
        return null
    }

    fun handleQNInfo(data: ByteArray, debug: ((String) -> Unit)? = null) {
        if (data.size > 10) {
            qnWeightDivisor = if (data[10].toInt() == 1) 100f else 10f
        }
        debug?.invoke("QN 0x12: weight divisor set to $qnWeightDivisor")
    }

    // --- 1byone / Eufy ---

    fun parse1byone(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 5) return null
        val raw = u16le(data, 3)
        val weightKg = raw / 100f
        val stable = data.size > 9 && data[9].toInt() != 1

        val wStr = "%.2f".format(weightKg)
        debug?.invoke("1byone CF: raw=$raw -> ${wStr}kg stable=$stable")
        if (weightKg in 0.1f..300f) return WeightReading(weightKg, stable)
        return null
    }

    // --- Hesley / YunChen ---

    fun parseHesley(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 20) return null

        val rawWeight = u16be(data, 2)
        val weight = rawWeight / 100f
        val rawFat = u16be(data, 4)
        val fat = rawFat / 10f

        if (weight !in 0.5f..300f) return null
        if (fat > 80f) return null

        val water = u16be(data, 8) / 10f
        val muscle = u16be(data, 10) / 10f
        val bone = u16be(data, 12) / 10f
        val kcal = u16be(data, 14)

        val wStr = "%.2f".format(weight)
        val fStr = "%.1f".format(fat)
        val waStr = "%.1f".format(water)
        val mStr = "%.1f".format(muscle)
        val bStr = "%.1f".format(bone)
        debug?.invoke("Hesley: ${wStr}kg fat=${fStr}% water=${waStr}% muscle=${mStr}% bone=${bStr}kg kcal=$kcal")
        return WeightReading(
            weightKg = weight,
            stable = true,
            fatPct = if (fat > 0f) fat else null,
            water = if (water > 0f) water else null,
            muscle = if (muscle > 0f) muscle else null,
            bone = if (bone > 0f) bone else null,
            kcal = if (kcal > 0) kcal else null,
        )
    }

    // --- Renpho ---

    fun parseRenpho(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 3 || data[0] != 0x2E.toByte()) return null
        val raw = ((data[2].toInt() and 0xFF) shl 8) or (data[1].toInt() and 0xFF)
        val weightKg = raw / 20f

        val wStr = "%.2f".format(weightKg)
        debug?.invoke("Renpho 2E: raw=$raw -> ${wStr}kg")
        if (weightKg in 0.1f..300f) return WeightReading(weightKg, stable = true)
        return null
    }

    // --- Safe generic fallback (body + food scales) ---

    fun parseGenericSafe(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 4) {
            debug?.invoke("generic: skip short packet (" + data.size + "B)")
            return null
        }

        data class Candidate(
            val pos: Int, val div: Float, val be: Boolean,
            val minKg: Float, val maxKg: Float, val label: String,
        )

        val candidates = listOf(
            // Food scale: raw value in grams (div=1 -> kg=raw/1000 via pos)
            Candidate(1, 1000f, false, 0.001f, 15f, "pos1 LE/g"),
            Candidate(1, 1000f, true, 0.001f, 15f, "pos1 BE/g"),
            Candidate(2, 1000f, false, 0.001f, 15f, "pos2 LE/g"),
            Candidate(2, 1000f, true, 0.001f, 15f, "pos2 BE/g"),
            Candidate(3, 1000f, false, 0.001f, 15f, "pos3 LE/g"),
            Candidate(3, 1000f, true, 0.001f, 15f, "pos3 BE/g"),
            // Food scale: raw in 0.1g (div=10000)
            Candidate(1, 10000f, false, 0.001f, 15f, "pos1 LE/0.1g"),
            Candidate(1, 10000f, true, 0.001f, 15f, "pos1 BE/0.1g"),
            Candidate(2, 10000f, false, 0.001f, 15f, "pos2 LE/0.1g"),
            Candidate(2, 10000f, true, 0.001f, 15f, "pos2 BE/0.1g"),
            // Food scale: raw in 0.5g (div=2000)
            Candidate(1, 2000f, false, 0.001f, 15f, "pos1 LE/0.5g"),
            Candidate(1, 2000f, true, 0.001f, 15f, "pos1 BE/0.5g"),
            // Body scale: standard divisors
            Candidate(1, 100f, true, 2f, 250f, "pos1 BE/100"),
            Candidate(1, 100f, false, 2f, 250f, "pos1 LE/100"),
            Candidate(3, 100f, true, 2f, 250f, "pos3 BE/100"),
            Candidate(3, 100f, false, 2f, 250f, "pos3 LE/100"),
            Candidate(2, 100f, true, 2f, 250f, "pos2 BE/100"),
            Candidate(2, 100f, false, 2f, 250f, "pos2 LE/100"),
            Candidate(1, 10f, true, 2f, 250f, "pos1 BE/10"),
            Candidate(1, 10f, false, 2f, 250f, "pos1 LE/10"),
            Candidate(3, 10f, true, 2f, 250f, "pos3 BE/10"),
            Candidate(3, 10f, false, 2f, 250f, "pos3 LE/10"),
            Candidate(1, 20f, true, 2f, 250f, "pos1 BE/20"),
            Candidate(1, 20f, false, 2f, 250f, "pos1 LE/20"),
        )

        for (c in candidates) {
            if (c.pos + 1 >= data.size) continue
            val raw = if (c.be) u16be(data, c.pos) else u16le(data, c.pos)
            if (raw == 0) continue
            val w = raw / c.div
            if (w in c.minKg..c.maxKg) {
                val grams = (w * 1000).toInt()
                val wStr = "%.3f".format(w)
                debug?.invoke("generic [" + c.label + "]: raw=$raw -> ${wStr}kg (${grams}g) (UNSTABLE)")
                return WeightReading(w, stable = false)
            }
        }
        debug?.invoke("generic: no valid candidate in " + data.size + " bytes")
        return null
    }

    // --- Helpers ---

    private fun u16le(b: ByteArray, off: Int): Int =
        (b[off].toInt() and 0xFF) or ((b[off + 1].toInt() and 0xFF) shl 8)

    private fun u16be(b: ByteArray, off: Int): Int =
        ((b[off].toInt() and 0xFF) shl 8) or (b[off + 1].toInt() and 0xFF)
}
