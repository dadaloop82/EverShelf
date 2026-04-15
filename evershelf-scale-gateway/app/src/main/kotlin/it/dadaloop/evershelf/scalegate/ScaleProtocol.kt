package it.dadaloop.evershelf.scalegate

import android.bluetooth.BluetoothGattCharacteristic
import java.util.UUID

// --- Data model ---

data class WeightReading(
    val grams: Int,
    val stable: Boolean,
)

// --- UUIDs ---

val CCCD_UUID: UUID = UUID.fromString("00002902-0000-1000-8000-00805f9b34fb")

object BleUuids {
    // BLE SIG Weight Scale (some kitchen scales use this)
    val WEIGHT_SCALE_SERVICE = UUID.fromString("0000181d-0000-1000-8000-00805f9b34fb")
    val WEIGHT_MEASUREMENT_CHAR = UUID.fromString("00002a9d-0000-1000-8000-00805f9b34fb")

    // Battery
    val BATTERY_SERVICE = UUID.fromString("0000180f-0000-1000-8000-00805f9b34fb")
    val BATTERY_LEVEL_CHAR = UUID.fromString("00002a19-0000-1000-8000-00805f9b34fb")

    // Common vendor services used by kitchen scales
    val FFE0 = UUID.fromString("0000ffe0-0000-1000-8000-00805f9b34fb")
    val FFE1 = UUID.fromString("0000ffe1-0000-1000-8000-00805f9b34fb")
    val FFF0 = UUID.fromString("0000fff0-0000-1000-8000-00805f9b34fb")
    val FFF1 = UUID.fromString("0000fff1-0000-1000-8000-00805f9b34fb")
    val FFF4 = UUID.fromString("0000fff4-0000-1000-8000-00805f9b34fb")

    // Acaia coffee scales
    val ACAIA_SERVICE = UUID.fromString("49535343-fe7d-4ae5-8fa9-9fafd205e455")
    val ACAIA_CHAR = UUID.fromString("49535343-8841-43f4-a8d4-ecbe34729bb3")
}

// --- Food scale protocol parser ---

object ScaleProtocol {

    // Max plausible kitchen scale weight: 15kg = 15000g
    private const val MAX_GRAMS = 15000
    private const val MIN_GRAMS = 1

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

        // Try known UUID-based parsers first
        when (char.uuid) {
            BleUuids.WEIGHT_MEASUREMENT_CHAR -> return parseSigWeight(data, debug)
        }

        // Try pattern-based parsers on any characteristic
        return parseGeneric(data, debug)
    }

    // --- BLE SIG 0x2A9D Weight Measurement ---
    // Kitchen scales that use the SIG profile send weight in
    // resolution of 0.005 kg (metric) or 0.01 lb (imperial).

    private fun parseSigWeight(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 3) return null
        val flags = data[0].toInt() and 0xFF
        val isImperial = (flags and 0x01) != 0
        val raw = u16le(data, 1)

        val grams = if (isImperial) {
            Math.round(raw * 0.01f * 453.592f)  // lb -> g
        } else {
            Math.round(raw * 5f)  // resolution 0.005kg = 5g per unit
        }

        val unit = if (isImperial) "lb" else "kg"
        debug?.invoke("SIG 2A9D: raw=$raw $unit -> ${grams}g")
        if (grams in MIN_GRAMS..MAX_GRAMS) return WeightReading(grams, stable = true)
        return null
    }

    // --- Generic food scale parser ---
    // Tries common data layouts used by BLE kitchen scales.
    // Many cheap kitchen scales send a simple frame with weight as uint16.

    private fun parseGeneric(data: ByteArray, debug: ((String) -> Unit)? = null): WeightReading? {
        if (data.size < 3) {
            debug?.invoke("skip: too short for generic (" + data.size + "B)")
            return null
        }

        data class C(
            val pos: Int,
            val be: Boolean,
            val toGrams: (Int) -> Int,
            val label: String,
        )

        // Candidates: position in frame, endianness, conversion to grams
        val candidates = listOf(
            // Raw = grams directly
            C(1, false, { it }, "pos1 LE grams"),
            C(1, true, { it }, "pos1 BE grams"),
            C(2, false, { it }, "pos2 LE grams"),
            C(2, true, { it }, "pos2 BE grams"),
            C(3, false, { it }, "pos3 LE grams"),
            C(3, true, { it }, "pos3 BE grams"),
            // Raw = 0.1g units (high-precision scales, e.g. coffee)
            C(1, false, { Math.round(it / 10f) }, "pos1 LE 0.1g"),
            C(1, true, { Math.round(it / 10f) }, "pos1 BE 0.1g"),
            C(2, false, { Math.round(it / 10f) }, "pos2 LE 0.1g"),
            C(2, true, { Math.round(it / 10f) }, "pos2 BE 0.1g"),
            // Raw = 0.5g units
            C(1, false, { Math.round(it * 0.5f) }, "pos1 LE 0.5g"),
            C(1, true, { Math.round(it * 0.5f) }, "pos1 BE 0.5g"),
            // Raw = centgrams (raw / 100 = kg, so raw * 10 = g)
            C(1, false, { it * 10 }, "pos1 LE cg"),
            C(1, true, { it * 10 }, "pos1 BE cg"),
            C(3, false, { it * 10 }, "pos3 LE cg"),
            C(3, true, { it * 10 }, "pos3 BE cg"),
            // Raw = kg*100 (body-style but small ranges work for food too)
            C(1, false, { Math.round(it * 10f) }, "pos1 LE kg100"),
            C(1, true, { Math.round(it * 10f) }, "pos1 BE kg100"),
            // Raw = oz * 10
            C(1, false, { Math.round(it * 2.835f) }, "pos1 LE oz10"),
            C(1, true, { Math.round(it * 2.835f) }, "pos1 BE oz10"),
        )

        for (c in candidates) {
            if (c.pos + 1 >= data.size) continue
            val raw = if (c.be) u16be(data, c.pos) else u16le(data, c.pos)
            if (raw == 0) continue
            val grams = c.toGrams(raw)
            if (grams in MIN_GRAMS..MAX_GRAMS) {
                debug?.invoke("generic [" + c.label + "]: raw=$raw -> ${grams}g (unstable)")
                return WeightReading(grams, stable = false)
            }
        }
        debug?.invoke("generic: no valid weight in " + data.size + " bytes")
        return null
    }

    // --- Helpers ---

    private fun u16le(b: ByteArray, off: Int): Int =
        (b[off].toInt() and 0xFF) or ((b[off + 1].toInt() and 0xFF) shl 8)

    private fun u16be(b: ByteArray, off: Int): Int =
        ((b[off].toInt() and 0xFF) shl 8) or (b[off + 1].toInt() and 0xFF)
}
