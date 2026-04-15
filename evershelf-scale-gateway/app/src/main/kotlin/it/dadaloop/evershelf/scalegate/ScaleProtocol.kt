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
)

/**
 * Descriptor UUID for enabling BLE notifications (standard 0x2902).
 */
val CCCD_UUID: UUID = UUID.fromString("00002902-0000-1000-8000-00805f9b34fb")

/**
 * Bluetooth SIG standard service and characteristic UUIDs.
 */
object BleUuids {
    // Weight Scale Service
    val WEIGHT_SCALE_SERVICE     = UUID.fromString("0000181d-0000-1000-8000-00805f9b34fb")
    val WEIGHT_MEASUREMENT_CHAR  = UUID.fromString("00002a9d-0000-1000-8000-00805f9b34fb")

    // Body Composition Service (also used by many smart scales)
    val BODY_COMPOSITION_SERVICE = UUID.fromString("0000181b-0000-1000-8000-00805f9b34fb")
    val BODY_COMPOSITION_CHAR    = UUID.fromString("00002a9c-0000-1000-8000-00805f9b34fb")

    // Battery Service
    val BATTERY_SERVICE          = UUID.fromString("0000180f-0000-1000-8000-00805f9b34fb")
    val BATTERY_LEVEL_CHAR       = UUID.fromString("00002a19-0000-1000-8000-00805f9b34fb")

    // Xiaomi Mi Scale 2 / Mi Body Composition Scale 2
    val XIAOMI_SCALE_SERVICE     = UUID.fromString("0000181b-0000-1000-8000-00805f9b34fb")
    val XIAOMI_SCALE_CHAR        = UUID.fromString("00002a9c-0000-1000-8000-00805f9b34fb")
}

/**
 * Parses BLE characteristic data for various scale protocols.
 * Returns a WeightReading or null if the data does not match a known format.
 */
object ScaleProtocol {

    /**
     * Attempt to parse weight data from a GATT characteristic change.
     * Tries known protocols in order of specificity.
     */
    fun parse(char: BluetoothGattCharacteristic, data: ByteArray, debugCallback: ((String) -> Unit)? = null): WeightReading? {
        return when (char.uuid) {
            BleUuids.WEIGHT_MEASUREMENT_CHAR  -> parseWeightMeasurement(data)
            BleUuids.BODY_COMPOSITION_CHAR    -> parseBodyComposition(data)
            else                              -> parseGeneric(data, debugCallback)
        }
    }

    /**
     * Bluetooth SIG Weight Measurement Characteristic (0x2A9D)
     *
     * Byte 0   : Flags
     *   Bit 0  = 0 → SI (kg/m), 1 → Imperial (lb/in)
     *   Bit 1  = Time Stamp present
     *   Bit 2  = User ID present
     *   Bit 3  = BMI & Height present
     * Bytes 1-2: Weight (uint16)
     *   SI:       0.005 kg per unit
     *   Imperial: 0.01 lb per unit
     */
    fun parseWeightMeasurement(data: ByteArray): WeightReading? {
        if (data.size < 3) return null
        val flags      = data[0].toInt() and 0xFF
        val isImperial = (flags and 0x01) != 0
        val rawWeight  = (data[1].toInt() and 0xFF) or ((data[2].toInt() and 0xFF) shl 8)

        val weightKg = if (isImperial) {
            rawWeight * 0.01f / 2.20462f   // lb → kg
        } else {
            rawWeight * 0.005f             // SI resolution
        }

        // Bit 3: BMI & Height present → offset 5 if no timestamp/user
        var bmi: Float? = null
        var offset = 3
        if ((flags and 0x02) != 0) offset += 7  // timestamp: 7 bytes
        if ((flags and 0x04) != 0) offset += 1  // user ID: 1 byte
        if ((flags and 0x08) != 0 && data.size >= offset + 4) {
            val rawBmi = (data[offset].toInt() and 0xFF) or ((data[offset + 1].toInt() and 0xFF) shl 8)
            bmi = rawBmi * 0.1f
        }

        return WeightReading(weightKg = weightKg, stable = true, bmi = bmi)
    }

    /**
     * Bluetooth SIG Body Composition Measurement Characteristic (0x2A9C)
     *
     * Bytes 0-1 : Flags (16-bit)
     *   Bit 0   = 0 → SI, 1 → Imperial
     *   Bit 1   = Time Stamp present
     *   Bit 7   = Weight present
     *   Bit 8   = Height present
     *   Bit 9   = Multiple Users
     *   Bit 10  = Basal Metabolism present
     *   Bit 11  = Muscle Percentage present
     *   Bit 13  = Body Fat Percentage present  ← always present (mandatory)
     * Bytes 2-3 : Body Fat % (uint16, resolution 0.1%)
     * … then optional fields
     * When Bit 7 (Weight) is set, weight (uint16) follows at an offset after other optionals.
     */
    fun parseBodyComposition(data: ByteArray): WeightReading? {
        if (data.size < 4) return null
        val flags      = (data[0].toInt() and 0xFF) or ((data[1].toInt() and 0xFF) shl 8)
        val isImperial = (flags and 0x0001) != 0

        // Body fat % (mandatory, bytes 2-3)
        val rawFat = (data[2].toInt() and 0xFF) or ((data[3].toInt() and 0xFF) shl 8)
        val fatPct = rawFat * 0.1f

        // Walk through optional fields to reach weight
        var offset = 4
        if ((flags and 0x0002) != 0) offset += 7  // timestamp
        if ((flags and 0x0200) != 0) offset += 1  // multiple users → User ID byte

        // Weight (Bit 7)
        var weightKg: Float? = null
        if ((flags and 0x0080) != 0 && data.size >= offset + 2) {
            val rawW = (data[offset].toInt() and 0xFF) or ((data[offset + 1].toInt() and 0xFF) shl 8)
            weightKg = if (isImperial) rawW * 0.01f / 2.20462f else rawW * 0.005f
            offset += 2
        }

        if (weightKg == null || weightKg <= 0f) return null

        return WeightReading(
            weightKg = weightKg,
            stable   = true,
            fatPct   = if (fatPct > 0f) fatPct else null,
        )
    }

    /**
     * Generic / fallback parser.
     * Tries every 2-byte window in the packet with three common resolutions
     * (0.1 kg, 0.01 kg, 0.005 kg) in both little-endian and big-endian order.
     * Reports the first result in the plausible range [1..300] kg.
     * The debugCallback receives the winning candidate or a failure message.
     */
    fun parseGeneric(data: ByteArray, debugCallback: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 2) return null

        data class Candidate(val start: Int, val res: Float, val be: Boolean)

        // Scan priorities: common flag-then-weight positions first, then brute-force remainder
        val preferred = listOf(1, 2, 3, data.size - 2, 0, 4, 5)
        val all = (preferred + (0 until data.size - 1).toList()).distinct()
            .filter { it in 0..data.size - 2 }

        val candidates = buildList {
            for (pos in all) {
                for (res in listOf(0.1f, 0.01f, 0.005f)) {
                    add(Candidate(pos, res, false))  // little-endian
                    add(Candidate(pos, res, true))   // big-endian
                }
            }
        }

        for (c in candidates) {
            val raw = if (c.be) {
                ((data[c.start].toInt() and 0xFF) shl 8) or (data[c.start + 1].toInt() and 0xFF)
            } else {
                (data[c.start].toInt() and 0xFF) or ((data[c.start + 1].toInt() and 0xFF) shl 8)
            }
            val weight = raw * c.res
            if (weight in 1f..300f) {
                val tag = if (c.be) "BE" else "LE"
                debugCallback?.invoke("\u2705 parseGeneric[$tag] start=${c.start} res=${c.res} raw=$raw \u2192 ${"%.3f".format(weight)} kg")
                return WeightReading(weightKg = weight, stable = true)
            }
        }
        debugCallback?.invoke("\u274c parseGeneric: nessun candidato in [1..300]kg su ${data.size} bytes")
        return null
    }
}
