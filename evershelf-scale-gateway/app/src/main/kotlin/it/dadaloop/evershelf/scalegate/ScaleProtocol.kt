package it.dadaloop.evershelf.scalegate

import android.bluetooth.BluetoothGattCharacteristic
import java.util.UUID

/**
 * Data model for a single weight reading from a BLE scale.
 */
data class WeightReading(
    val weightKg: Float,       // weight in kilograms
    val stable: Boolean,       // true when the reading is stable/final
    val battery: Int? = null,  // battery percentage (0-100), if reported
    val fatPct: Float? = null, // body fat %, if available
    val bmi: Float? = null,    // BMI, if available
    val muscle: Float? = null, // muscle mass %, if available
    val water: Float? = null,  // water %, if available
    val bone: Float? = null,   // bone mass kg, if available
    val kcal: Int? = null,     // BMR / kcal, if available
    val impedance: Float? = null, // impedance Ω, if available
)

/**
 * Descriptor UUID for enabling BLE notifications (standard 0x2902).
 */
val CCCD_UUID: UUID = UUID.fromString("00002902-0000-1000-8000-00805f9b34fb")

/**
 * Bluetooth SIG standard + common vendor service/characteristic UUIDs.
 */
object BleUuids {
    // Weight Scale Service
    val WEIGHT_SCALE_SERVICE     = UUID.fromString("0000181d-0000-1000-8000-00805f9b34fb")
    val WEIGHT_MEASUREMENT_CHAR  = UUID.fromString("00002a9d-0000-1000-8000-00805f9b34fb")

    // Body Composition Service
    val BODY_COMPOSITION_SERVICE = UUID.fromString("0000181b-0000-1000-8000-00805f9b34fb")
    val BODY_COMPOSITION_CHAR    = UUID.fromString("00002a9c-0000-1000-8000-00805f9b34fb")

    // Battery Service
    val BATTERY_SERVICE          = UUID.fromString("0000180f-0000-1000-8000-00805f9b34fb")
    val BATTERY_LEVEL_CHAR       = UUID.fromString("00002a19-0000-1000-8000-00805f9b34fb")

    // QN/Yolanda/FITINDEX (Type 1)
    val QN_SERVICE_FFE0  = UUID.fromString("0000ffe0-0000-1000-8000-00805f9b34fb")
    val QN_NOTIFY_FFE1   = UUID.fromString("0000ffe1-0000-1000-8000-00805f9b34fb")
    val QN_WRITE_FFE3    = UUID.fromString("0000ffe3-0000-1000-8000-00805f9b34fb")
    val QN_WRITE_FFE4    = UUID.fromString("0000ffe4-0000-1000-8000-00805f9b34fb")

    // Common custom services (QN Type 2, 1byone, Eufy, Hesley, etc.)
    val CUSTOM_FFF0      = UUID.fromString("0000fff0-0000-1000-8000-00805f9b34fb")
    val CUSTOM_FFF1      = UUID.fromString("0000fff1-0000-1000-8000-00805f9b34fb")
    val CUSTOM_FFF2      = UUID.fromString("0000fff2-0000-1000-8000-00805f9b34fb")
    val CUSTOM_FFF4      = UUID.fromString("0000fff4-0000-1000-8000-00805f9b34fb")
}

/**
 * Protocol-aware parser for BLE scale data.
 *
 * Supports:
 *  - Bluetooth SIG Weight Measurement (0x2A9D) and Body Composition (0x2A9C)
 *  - QN/Yolanda/FITINDEX protocol (opcode 0x10 weight, 0x12 info)
 *  - 1byone/Eufy protocol (0xCF frames)
 *  - Hesley/YunChen (20-byte body-composition frames)
 *  - Renpho proprietary (0x2E header)
 *  - Safe generic fallback (stricter than brute-force)
 */
object ScaleProtocol {

    // ── QN protocol state (weight divisor set by 0x12 info frame) ───────────
    @Volatile
    private var qnWeightDivisor: Float = 100f

    /** Call when starting a new connection to reset protocol state. */
    fun resetState() {
        qnWeightDivisor = 100f
    }

    // ── Main entry point ────────────────────────────────────────────────────

    fun parse(
        char: BluetoothGattCharacteristic,
        data: ByteArray,
        debug: ((String) -> Unit)? = null,
    ): WeightReading? {
        // 1. Standard BLE SIG characteristics (identified by UUID)
        when (char.uuid) {
            BleUuids.WEIGHT_MEASUREMENT_CHAR -> {
                // Renpho uses standard UUID but proprietary encoding (header 0x2E)
                if (data.isNotEmpty() && data[0] == 0x2E.toByte()) {
                    return parseRenpho(data, debug)
                }
                return parseWeightMeasurement(data, debug)
            }
            BleUuids.BODY_COMPOSITION_CHAR -> return parseBodyComposition(data, debug)
        }

        // 2. Vendor protocol detection from data content
        if (data.isEmpty()) return null
        val opcode = data[0].toInt() and 0xFF

        // QN/Yolanda family
        if (opcode == 0x10 && data.size >= 6) return parseQNWeight(data, debug)
        if (opcode == 0x12 && data.size > 2) { handleQNInfo(data, debug); return null }
        if (opcode in listOf(0x14, 0x20, 0x21, 0xA1, 0xA3)) {
            debug?.invoke("QN ack/handshake opcode=0x${"%02X".format(opcode)}")
            return null
        }

        // 1byone / Eufy
        if (opcode == 0xCF && data.size >= 11) return parse1byone(data, debug)

        // Renpho on custom characteristic
        if (opcode == 0x2E && data.size >= 3) return parseRenpho(data, debug)

        // Hesley / YunChen (exactly 20 bytes, validated)
        if (data.size == 20) {
            val result = parseHesley(data, debug)
            if (result != null) return result
        }

        // 3. Safe generic fallback
        return parseGenericSafe(data, debug)
    }

    // ── Bluetooth SIG 0x2A9D ────────────────────────────────────────────────

    fun parseWeightMeasurement(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 3) return null
        val flags      = data[0].toInt() and 0xFF
        val isImperial = (flags and 0x01) != 0
        val rawWeight  = u16le(data, 1)

        val weightKg = if (isImperial) {
            rawWeight * 0.01f / 2.20462f
        } else {
            rawWeight * 0.005f
        }

        var bmi: Float? = null
        var offset = 3
        if ((flags and 0x02) != 0) offset += 7  // timestamp
        if ((flags and 0x04) != 0) offset += 1  // user ID
        if ((flags and 0x08) != 0 && data.size >= offset + 4) {
            val rawBmi = u16le(data, offset)
            bmi = rawBmi * 0.1f
        }

        val unit = if (isImperial) "lb" else "kg"
        debug?.invoke("SIG 2A9D: raw=$rawWeight imp=$isImperial -> ${"%.2f".format(weightKg)}kg ($unit)")
        if (weightKg in 0.1f..500f) return WeightReading(weightKg, stable = true, bmi = bmi)
        return null
    }

    // ── Bluetooth SIG 0x2A9C ────────────────────────────────────────────────

    fun parseBodyComposition(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 4) return null
        val flags      = u16le(data, 0)
        val isImperial = (flags and 0x0001) != 0

        val rawFat = u16le(data, 2)
        val fatPct = rawFat * 0.1f

        var offset = 4
        if ((flags and 0x0002) != 0) offset += 7  // timestamp
        if ((flags and 0x0200) != 0) offset += 1  // user ID

        var weightKg: Float? = null
        if ((flags and 0x0080) != 0 && data.size >= offset + 2) {
            val rawW = u16le(data, offset)
            weightKg = if (isImperial) rawW * 0.01f / 2.20462f else rawW * 0.005f
            offset += 2
        }

        if (weightKg == null || weightKg <= 0f) return null

        debug?.invoke("SIG 2A9C: weight=${"%.2f".format(weightKg)}kg fat=${"%.1f".format(fatPct)}%")
        return WeightReading(
            weightKg = weightKg,
            stable   = true,
            fatPct   = if (fatPct > 0f) fatPct else null,
        )
    }

    // ── QN / Yolanda / FITINDEX ─────────────────────────────────────────────

    /**
     * QN 0x10 weight frame.
     * Two layouts:
     *  Original: [0x10][len][proto][weight_hi][weight_lo][stable][r1_hi][r1_lo][r2_hi][r2_lo]
     *  ES-30M:   [0x10][len][proto][unit][stable][weight_hi][weight_lo][r1...][r2...]
     */
    fun parseQNWeight(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 6) return null

        val byte4 = data[4].toInt() and 0xFF
        // ES-30M: byte[4] is a stable flag (0x00/0x01/0x02), and divisor is 10
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
        // Heuristic: if weight is unreasonable with the current divisor, try the other
        if (weightKg < 1f || weightKg > 300f) {
            val alt = if (qnWeightDivisor == 100f) 10f else 100f
            val altW = raw / alt
            if (altW in 1f..300f) {
                weightKg = altW
                debug?.invoke("QN: auto-adjusted divisor $qnWeightDivisor -> $alt")
            }
        }

        debug?.invoke("QN 0x10: raw=$raw div=$qnWeightDivisor -> ${"%.2f".format(weightKg)}kg stable=$stable")
        if (weightKg in 0.1f..300f) return WeightReading(weightKg, stable)
        return null
    }

    /** QN 0x12 scale info frame. Byte[10] tells us the weight scaling factor. */
    fun handleQNInfo(data: ByteArray, debug: ((String) -> Unit)? = null) {
        if (data.size > 10) {
            qnWeightDivisor = if (data[10].toInt() == 1) 100f else 10f
        }
        debug?.invoke("QN 0x12: weight divisor set to $qnWeightDivisor")
    }

    // ── 1byone / Eufy ───────────────────────────────────────────────────────

    /**
     * 1byone protocol: 0xCF header, weight at bytes [3..4] as uint16 LE / 100.
     * Byte[9] == 1 means impedance not present (we treat as "still measuring").
     */
    fun parse1byone(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 5) return null
        val raw = u16le(data, 3)
        val weightKg = raw / 100f
        val stable = data.size > 9 && data[9].toInt() != 1

        debug?.invoke("1byone CF: raw=$raw -> ${"%.2f".format(weightKg)}kg stable=$stable")
        if (weightKg in 0.1f..300f) return WeightReading(weightKg, stable)
        return null
    }

    // ── Hesley / YunChen ────────────────────────────────────────────────────

    /**
     * 20-byte frame with full body composition:
     *  [2..3] weight (BE, /100), [4..5] fat (BE, /10), [8..9] water (BE, /10),
     *  [10..11] muscle (BE, /10), [12..13] bone (BE, /10), [14..15] kcal (BE).
     */
    fun parseHesley(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 20) return null

        val rawWeight = u16be(data, 2)
        val weight = rawWeight / 100f
        val rawFat = u16be(data, 4)
        val fat = rawFat / 10f

        // Validation: weight must be plausible and fat % must be reasonable
        if (weight !in 0.5f..300f) return null
        if (fat > 80f) return null

        val water  = u16be(data, 8) / 10f
        val muscle = u16be(data, 10) / 10f
        val bone   = u16be(data, 12) / 10f
        val kcal   = u16be(data, 14)

        debug?.invoke("Hesley: ${"%.2f".format(weight)}kg fat=${"%.1f".format(fat)}% water=${"%.1f".format(water)}% muscle=${"%.1f".format(muscle)}% bone=${"%.1f".format(bone)}kg kcal=$kcal")
        return WeightReading(
            weightKg = weight,
            stable   = true,
            fatPct   = if (fat > 0f) fat else null,
            water    = if (water > 0f) water else null,
            muscle   = if (muscle > 0f) muscle else null,
            bone     = if (bone > 0f) bone else null,
            kcal     = if (kcal > 0) kcal else null,
        )
    }

    // ── Renpho ──────────────────────────────────────────────────────────────

    /**
     * Renpho proprietary on 0x2A9D: header 0x2E, weight = u16mix(1,2) / 20.
     */
    fun parseRenpho(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 3 || data[0] != 0x2E.toByte()) return null
        // Renpho uses (data[2] << 8 | data[1]) i.e. big-endian-ish
        val raw = ((data[2].toInt() and 0xFF) shl 8) or (data[1].toInt() and 0xFF)
        val weightKg = raw / 20f

        debug?.invoke("Renpho 2E: raw=$raw -> ${"%.2f".format(weightKg)}kg")
        if (weightKg in 0.1f..300f) return WeightReading(weightKg, stable = true)
        return null
    }

    // ── Safe generic fallback ───────────────────────────────────────────────

    /**
     * Conservative fallback parser.
     * Only tries a few positions with strict validation.
     * Returns readings as unstable to signal uncertainty.
     */
    fun parseGenericSafe(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        // Skip very short packets (usually control/ack frames)
        if (data.size < 4) {
            debug?.invoke("generic: skip short packet (${data.size}B)")
            return null
        }

        // Try the most common positions and divisors
        data class Candidate(val pos: Int, val div: Float, val be: Boolean, val label: String)
        val candidates = listOf(
            Candidate(1, 100f, true,  "pos1 BE/100"),
            Candidate(1, 100f, false, "pos1 LE/100"),
            Candidate(3, 100f, true,  "pos3 BE/100"),
            Candidate(3, 100f, false, "pos3 LE/100"),
            Candidate(2, 100f, true,  "pos2 BE/100"),
            Candidate(2, 100f, false, "pos2 LE/100"),
            Candidate(1, 10f,  true,  "pos1 BE/10"),
            Candidate(1, 10f,  false, "pos1 LE/10"),
            Candidate(3, 10f,  true,  "pos3 BE/10"),
            Candidate(3, 10f,  false, "pos3 LE/10"),
            Candidate(1, 20f,  true,  "pos1 BE/20"),
            Candidate(1, 20f,  false, "pos1 LE/20"),
        )

        for (c in candidates) {
            if (c.pos + 1 >= data.size) continue
            val raw = if (c.be) u16be(data, c.pos) else u16le(data, c.pos)
            val w = raw / c.div
            // Strict range: 2 kg minimum to avoid false positives from control bytes
            if (w in 2f..250f) {
                debug?.invoke("generic [${c.label}]: raw=$raw -> ${"%.2f".format(w)}kg (UNSTABLE)")
                return WeightReading(w, stable = false)
            }
        }
        debug?.invoke("generic: no valid candidate in ${data.size} bytes")
        return null
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private fun u16le(b: ByteArray, off: Int): Int =
        (b[off].toInt() and 0xFF) or ((b[off + 1].toInt() and 0xFF) shl 8)

    private fun u16be(b: ByteArray, off: Int): Int =
        ((b[off].toInt() and 0xFF) shl 8) or (b[off + 1].toInt() and 0xFF)
}
