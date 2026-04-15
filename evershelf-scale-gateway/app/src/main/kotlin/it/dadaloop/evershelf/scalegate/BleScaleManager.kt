package it.dadaloop.evershelf.scalegate

import android.Manifest
import android.bluetooth.*
import android.bluetooth.le.*
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.content.ContextCompat

private const val TAG = "BleScaleManager"
private const val SCAN_PERIOD_MS = 15_000L

/**
 * Represents a discovered BLE device during scan.
 */
data class BleDeviceInfo(
    val device: BluetoothDevice,
    val name: String,
    val rssi: Int,
    val proximity: String,
    val scaleScore: Int,
)

/**
 * Callback interface for BLE events dispatched back to the UI.
 */
interface BleScaleListener {
    fun onDeviceFound(info: BleDeviceInfo)
    fun onConnecting(device: BluetoothDevice)
    fun onConnected(deviceName: String)
    fun onDisconnected()
    fun onWeightReceived(reading: WeightReading)
    fun onBatteryReceived(level: Int)
    fun onError(message: String)
    fun onScanStopped()
    fun onDebugEvent(message: String)
}

/**
 * Manages BLE scanning and connection to a smart scale.
 * All listener callbacks are dispatched on the main thread.
 */
class BleScaleManager(
    private val context: Context,
    private val listener: BleScaleListener,
) {
    private val bluetoothManager = context.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager
    private val bluetoothAdapter: BluetoothAdapter? get() = bluetoothManager.adapter
    private val mainHandler = Handler(Looper.getMainLooper())

    private var leScanner: BluetoothLeScanner? = null
    private var gatt: BluetoothGatt? = null
    private var isScanning = false
    private var connectedDeviceName: String = ""

    // The characteristics we will subscribe to (multiple may exist).
    private val pendingSubscriptions = ArrayDeque<BluetoothGattCharacteristic>()

    // ─── Public state ──────────────────────────────────────────────────────────

    val isConnected: Boolean get() = gatt != null && connectedDeviceName.isNotEmpty()

    // ─── Permissions helper ────────────────────────────────────────────────────

    fun hasRequiredPermissions(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_SCAN) == PackageManager.PERMISSION_GRANTED &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED
        } else {
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
        }
    }

    // ─── Scanning ──────────────────────────────────────────────────────────────

    fun startScan() {
        val adapter = bluetoothAdapter ?: run {
            listener.onError("Bluetooth non disponibile su questo dispositivo.")
            return
        }
        if (!adapter.isEnabled) {
            listener.onError("Bluetooth disattivato. Attivalo e riprova.")
            return
        }
        if (isScanning) stopScan()

        leScanner = adapter.bluetoothLeScanner
        val settings = ScanSettings.Builder()
            .setScanMode(ScanSettings.SCAN_MODE_LOW_LATENCY)
            .build()

        // No service UUID filters — many consumer scales use proprietary UUIDs
        // and would be invisible with strict filtering. We show all named BLE devices.
        isScanning = true
        try {
            leScanner?.startScan(null, settings, scanCallback)
        } catch (e: Exception) {
            leScanner?.startScan(scanCallback)
        }

        // Auto-stop after SCAN_PERIOD_MS
        mainHandler.postDelayed({
            stopScan()
            listener.onScanStopped()
        }, SCAN_PERIOD_MS)
    }

    fun stopScan() {
        if (!isScanning) return
        isScanning = false
        try {
            leScanner?.stopScan(scanCallback)
        } catch (e: Exception) { /* ignore */ }
        leScanner = null
    }

    private val scanCallback = object : ScanCallback() {
        override fun onScanResult(callbackType: Int, result: ScanResult) {
            val device = result.device
            val name = result.scanRecord?.deviceName?.takeIf { it.isNotBlank() }
                ?: getDeviceName(device)
            val proximity = rssiToProximity(result.rssi)
            val score = scoreLikelyScale(name, result.scanRecord)
            val info = BleDeviceInfo(device, name, result.rssi, proximity, score)
            mainHandler.post { listener.onDeviceFound(info) }
        }

        override fun onScanFailed(errorCode: Int) {
            isScanning = false
            mainHandler.post { listener.onError("Scansione BLE fallita (codice: $errorCode)") }
        }
    }

    private fun getDeviceName(device: BluetoothDevice): String {
        return try {
            device.name?.takeIf { it.isNotBlank() } ?: "Senza nome"
        } catch (e: SecurityException) {
            "Senza nome"
        }
    }

    private fun rssiToProximity(rssi: Int) = when {
        rssi >= -60 -> "📶 Vicino"
        rssi >= -80 -> "📶 Medio"
        else        -> "📶 Lontano"
    }

    private fun scoreLikelyScale(name: String, scanRecord: android.bluetooth.le.ScanRecord?): Int {
        var score = 0
        val lower = name.lowercase()
        if (listOf("scale", "bilancia", "weight", "body", "balance",
                   "lepulse", "qardio", "xiaomi", "mi body", "körper")
                .any { lower.contains(it) }) score += 10
        scanRecord?.serviceUuids?.let { uuids ->
            val us = uuids.map { it.uuid.toString().lowercase() }
            if (us.any { it.startsWith("0000181d") || it.startsWith("0000181b") }) score += 20
        }
        return score
    }

    // ─── Connection ────────────────────────────────────────────────────────────

    fun connect(device: BluetoothDevice) {
        stopScan()
        disconnect()
        connectedDeviceName = ""
        mainHandler.post { listener.onConnecting(device) }
        try {
            gatt = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                device.connectGatt(context, false, gattCallback, BluetoothDevice.TRANSPORT_LE)
            } else {
                device.connectGatt(context, false, gattCallback)
            }
        } catch (e: SecurityException) {
            mainHandler.post { listener.onError("Permesso mancante: ${e.message}") }
        }
    }

    fun disconnect() {
        pendingSubscriptions.clear()
        try {
            gatt?.disconnect()
            gatt?.close()
        } catch (e: Exception) { /* ignore */ }
        gatt = null
        connectedDeviceName = ""
    }

    // ─── GATT callbacks ────────────────────────────────────────────────────────

    private val gattCallback = object : BluetoothGattCallback() {

        override fun onConnectionStateChange(gatt: BluetoothGatt, status: Int, newState: Int) {
            when (newState) {
                BluetoothProfile.STATE_CONNECTED -> {
                    Log.d(TAG, "Connected — discovering services…")
                    mainHandler.postDelayed({ gatt.discoverServices() }, 500)
                }
                BluetoothProfile.STATE_DISCONNECTED -> {
                    Log.d(TAG, "Disconnected (status=$status)")
                    this@BleScaleManager.gatt?.close()
                    this@BleScaleManager.gatt = null
                    connectedDeviceName = ""
                    mainHandler.post { listener.onDisconnected() }
                }
            }
        }

        override fun onServicesDiscovered(gatt: BluetoothGatt, status: Int) {
            if (status != BluetoothGatt.GATT_SUCCESS) {
                mainHandler.post { listener.onError("Servizi GATT non trovati (status=$status)") }
                return
            }

            val targetChars = mutableListOf<BluetoothGattCharacteristic>()

            // Priority 1: Weight Scale Service
            gatt.getService(BleUuids.WEIGHT_SCALE_SERVICE)
                ?.getCharacteristic(BleUuids.WEIGHT_MEASUREMENT_CHAR)
                ?.let { targetChars.add(it) }

            // Priority 2: Body Composition Service
            gatt.getService(BleUuids.BODY_COMPOSITION_SERVICE)
                ?.getCharacteristic(BleUuids.BODY_COMPOSITION_CHAR)
                ?.let { if (!targetChars.contains(it)) targetChars.add(it) }

            // Fallback: any notifiable characteristic from unknown services
            if (targetChars.isEmpty()) {
                for (service in gatt.services) {
                    // Skip standard generic services
                    if (service.uuid.toString().startsWith("00001800") ||
                        service.uuid.toString().startsWith("00001801")) continue
                    for (char in service.characteristics) {
                        val props = char.properties
                        if ((props and BluetoothGattCharacteristic.PROPERTY_NOTIFY) != 0 ||
                            (props and BluetoothGattCharacteristic.PROPERTY_INDICATE) != 0) {
                            targetChars.add(char)
                        }
                    }
                }
            }

            if (targetChars.isEmpty()) {
                mainHandler.post { listener.onError("Nessuna caratteristica peso trovata su questa bilancia.") }
                return
            }

            // Battery (optional)
            gatt.getService(BleUuids.BATTERY_SERVICE)
                ?.getCharacteristic(BleUuids.BATTERY_LEVEL_CHAR)
                ?.let { targetChars.add(it) }

            // Debug: log all discovered services and characteristics
            val dbg = buildString {
                append("Servizi GATT (${gatt.services.size}):\n")
                for (svc in gatt.services) {
                    append("  SVC: ${svc.uuid}\n")
                    for (ch in svc.characteristics) {
                        val p = ch.properties
                        val flags = buildString {
                            if (p and BluetoothGattCharacteristic.PROPERTY_NOTIFY != 0) append("N")
                            if (p and BluetoothGattCharacteristic.PROPERTY_INDICATE != 0) append("I")
                            if (p and BluetoothGattCharacteristic.PROPERTY_READ != 0) append("R")
                            if (p and BluetoothGattCharacteristic.PROPERTY_WRITE != 0) append("W")
                        }
                        append("    CHAR: ${ch.uuid} [$flags]\n")
                    }
                }
                append("Iscritto a ${targetChars.size} caratteristiche")
            }
            mainHandler.post { listener.onDebugEvent(dbg) }

            pendingSubscriptions.clear()
            pendingSubscriptions.addAll(targetChars)

            val deviceName = try { gatt.device?.name ?: "Bilancia" } catch (e: SecurityException) { "Bilancia" }
            connectedDeviceName = deviceName
            mainHandler.post { listener.onConnected(deviceName) }

            // Subscribe one at a time (Android BLE requires sequential descriptor writes)
            subscribeNext(gatt)
        }

        override fun onDescriptorWrite(gatt: BluetoothGatt, descriptor: BluetoothGattDescriptor, status: Int) {
            // Subscribe to the next characteristic
            subscribeNext(gatt)
        }

        @Suppress("DEPRECATION")
        override fun onCharacteristicChanged(
            gatt: BluetoothGatt,
            characteristic: BluetoothGattCharacteristic,
        ) {
            val data = characteristic.value ?: return
            processCharacteristicData(characteristic, data)
        }

        // Android 13+ override
        override fun onCharacteristicChanged(
            gatt: BluetoothGatt,
            characteristic: BluetoothGattCharacteristic,
            value: ByteArray,
        ) {
            processCharacteristicData(characteristic, value)
        }

        @Suppress("DEPRECATION")
        override fun onCharacteristicRead(
            gatt: BluetoothGatt,
            characteristic: BluetoothGattCharacteristic,
            status: Int,
        ) {
            if (status == BluetoothGatt.GATT_SUCCESS && characteristic.uuid == BleUuids.BATTERY_LEVEL_CHAR) {
                val level = characteristic.value?.firstOrNull()?.toInt()?.and(0xFF)
                if (level != null) mainHandler.post { listener.onBatteryReceived(level) }
            }
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private fun subscribeNext(gatt: BluetoothGatt) {
        val char = pendingSubscriptions.removeFirstOrNull() ?: return

        // Battery characteristic — read once instead of notify
        if (char.uuid == BleUuids.BATTERY_LEVEL_CHAR) {
            try { gatt.readCharacteristic(char) } catch (e: SecurityException) { /* ignore */ }
            return
        }

        val props = char.properties
        val notifyType = when {
            (props and BluetoothGattCharacteristic.PROPERTY_INDICATE) != 0 ->
                BluetoothGattDescriptor.ENABLE_INDICATION_VALUE
            else -> BluetoothGattDescriptor.ENABLE_NOTIFICATION_VALUE
        }

        try {
            gatt.setCharacteristicNotification(char, true)
            val descriptor = char.getDescriptor(CCCD_UUID) ?: run {
                // No CCCD — skip and try next
                subscribeNext(gatt)
                return
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                gatt.writeDescriptor(descriptor, notifyType)
            } else {
                @Suppress("DEPRECATION")
                descriptor.value = notifyType
                @Suppress("DEPRECATION")
                gatt.writeDescriptor(descriptor)
            }
        } catch (e: SecurityException) {
            Log.e(TAG, "SecurityException enabling notification", e)
        }
    }

    private fun processCharacteristicData(char: BluetoothGattCharacteristic, data: ByteArray) {
        // Battery level
        if (char.uuid == BleUuids.BATTERY_LEVEL_CHAR && data.isNotEmpty()) {
            val level = data[0].toInt() and 0xFF
            mainHandler.post { listener.onBatteryReceived(level) }
            return
        }

        // Debug: log raw bytes received
        val hex = data.joinToString(" ") { "%02X".format(it) }
        mainHandler.post { listener.onDebugEvent("📡 ${char.uuid}\n   HEX [${data.size}B]: $hex") }

        // Weight / body composition
        val reading = ScaleProtocol.parse(char, data) { msg ->
            mainHandler.post { listener.onDebugEvent(msg) }
        }
        if (reading != null && reading.weightKg > 0f) {
            mainHandler.post { listener.onWeightReceived(reading) }
        } else {
            mainHandler.post { listener.onDebugEvent("⚠️ Peso non decodificato") }
        }
    }
}
