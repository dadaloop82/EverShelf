package it.dadaloop.evershelf.kiosk

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
private const val PREFS_NAME = "evershelf_kiosk"
private const val PREF_LAST_DEVICE = "last_device_address"

data class BleDeviceInfo(
    val device: BluetoothDevice,
    val name: String,
    val rssi: Int,
    val proximity: String,
    val scaleScore: Int,
)

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
    private var autoConnectAddress: String? = null
    private val pendingSubscriptions = ArrayDeque<BluetoothGattCharacteristic>()

    val isConnected: Boolean get() = gatt != null && connectedDeviceName.isNotEmpty()

    fun getSavedDeviceAddress(): String? {
        return context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(PREF_LAST_DEVICE, null)
    }

    private fun saveDeviceAddress(address: String) {
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit().putString(PREF_LAST_DEVICE, address).apply()
    }

    fun enableAutoConnect() {
        autoConnectAddress = getSavedDeviceAddress()
    }

    fun hasRequiredPermissions(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_SCAN) == PackageManager.PERMISSION_GRANTED &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED
        } else {
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
        }
    }

    fun startScan() {
        val adapter = bluetoothAdapter ?: run {
            listener.onError("Bluetooth not available.")
            return
        }
        if (!adapter.isEnabled) {
            listener.onError("Bluetooth is off.")
            return
        }
        if (isScanning) stopScan()

        leScanner = adapter.bluetoothLeScanner
        val settings = ScanSettings.Builder()
            .setScanMode(ScanSettings.SCAN_MODE_LOW_LATENCY)
            .build()

        isScanning = true
        try {
            leScanner?.startScan(null, settings, scanCallback)
        } catch (e: Exception) {
            leScanner?.startScan(scanCallback)
        }

        mainHandler.postDelayed({
            stopScan()
            listener.onScanStopped()
        }, SCAN_PERIOD_MS)
    }

    fun stopScan() {
        if (!isScanning) return
        isScanning = false
        try { leScanner?.stopScan(scanCallback) } catch (_: Exception) {}
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

            if (autoConnectAddress != null && device.address == autoConnectAddress && !isConnected) {
                autoConnectAddress = null
                mainHandler.post { connect(device) }
            }
        }

        override fun onScanFailed(errorCode: Int) {
            isScanning = false
            mainHandler.post { listener.onError("BLE scan failed (code: $errorCode)") }
        }
    }

    private fun getDeviceName(device: BluetoothDevice): String {
        return try { device.name?.takeIf { it.isNotBlank() } ?: "Unnamed" } catch (_: SecurityException) { "Unnamed" }
    }

    private fun rssiToProximity(rssi: Int) = when {
        rssi >= -60 -> "Near"; rssi >= -80 -> "Medium"; else -> "Far"
    }

    private fun scoreLikelyScale(name: String, scanRecord: android.bluetooth.le.ScanRecord?): Int {
        var score = 0
        val lower = name.lowercase()
        val foodKeywords = listOf("scale", "bilancia", "kitchen", "food", "cucina", "coffee", "caffe",
            "balance", "weight", "waage", "arboleaf", "ck10", "ck20", "ek-", "acaia", "felicita",
            "decent", "skale", "timemore", "brewista", "hario", "greater goods", "ozeri", "etekcity",
            "nutri", "nicewell", "koios", "renpho", "eatsmart")
        if (foodKeywords.any { lower.contains(it) }) score += 10
        val bodyKeywords = listOf("body", "fat", "bmi", "composition", "fitness", "mi body", "lepulse", "qardio", "garmin", "withings")
        if (bodyKeywords.any { lower.contains(it) }) score -= 5
        scanRecord?.serviceUuids?.let { uuids ->
            val us = uuids.map { it.uuid.toString().lowercase() }
            if (us.any { it.startsWith("0000181d") }) score += 15
            if (us.any { it.startsWith("0000ffe0") || it.startsWith("0000fff0") }) score += 10
            if (us.any { it.startsWith("49535343") }) score += 20
            if (us.any { it.startsWith("0000181b") }) score -= 10
        }
        return score
    }

    fun connect(device: BluetoothDevice) {
        stopScan()
        disconnect()
        connectedDeviceName = ""
        ScaleProtocol.resetState()
        mainHandler.post { listener.onConnecting(device) }
        try {
            gatt = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                device.connectGatt(context, false, gattCallback, BluetoothDevice.TRANSPORT_LE)
            } else {
                device.connectGatt(context, false, gattCallback)
            }
        } catch (e: SecurityException) {
            mainHandler.post { listener.onError("Missing permission: ${e.message}") }
        }
    }

    fun disconnect() {
        pendingSubscriptions.clear()
        try { gatt?.disconnect(); gatt?.close() } catch (_: Exception) {}
        gatt = null
        connectedDeviceName = ""
    }

    private val gattCallback = object : BluetoothGattCallback() {
        override fun onConnectionStateChange(gatt: BluetoothGatt, status: Int, newState: Int) {
            when (newState) {
                BluetoothProfile.STATE_CONNECTED -> {
                    mainHandler.postDelayed({ gatt.discoverServices() }, 500)
                }
                BluetoothProfile.STATE_DISCONNECTED -> {
                    this@BleScaleManager.gatt?.close()
                    this@BleScaleManager.gatt = null
                    connectedDeviceName = ""
                    mainHandler.post { listener.onDisconnected() }
                }
            }
        }

        override fun onServicesDiscovered(gatt: BluetoothGatt, status: Int) {
            if (status != BluetoothGatt.GATT_SUCCESS) {
                mainHandler.post { listener.onError("GATT services not found (status=$status)") }
                return
            }

            val targetChars = mutableListOf<BluetoothGattCharacteristic>()

            gatt.getService(BleUuids.WEIGHT_SCALE_SERVICE)
                ?.getCharacteristic(BleUuids.WEIGHT_MEASUREMENT_CHAR)?.let { targetChars.add(it) }
            gatt.getService(BleUuids.FFE0)?.let { svc ->
                svc.getCharacteristic(BleUuids.FFE1)?.let { targetChars.add(it) }
            }
            gatt.getService(BleUuids.FFF0)?.let { svc ->
                svc.getCharacteristic(BleUuids.FFF4)?.let { targetChars.add(it) }
                    ?: svc.getCharacteristic(BleUuids.FFF1)?.let { targetChars.add(it) }
            }
            gatt.getService(BleUuids.ACAIA_SERVICE)?.let { svc ->
                svc.getCharacteristic(BleUuids.ACAIA_CHAR)?.let { targetChars.add(it) }
            }

            if (targetChars.isEmpty()) {
                for (service in gatt.services) {
                    if (service.uuid.toString().startsWith("00001800") ||
                        service.uuid.toString().startsWith("00001801")) continue
                    for (char in service.characteristics) {
                        val props = char.properties
                        if ((props and BluetoothGattCharacteristic.PROPERTY_NOTIFY) != 0 ||
                            (props and BluetoothGattCharacteristic.PROPERTY_INDICATE) != 0) {
                            if (!targetChars.contains(char)) targetChars.add(char)
                        }
                    }
                }
            }

            if (targetChars.isEmpty()) {
                mainHandler.post { listener.onError("No weight characteristic found.") }
                return
            }

            gatt.getService(BleUuids.BATTERY_SERVICE)
                ?.getCharacteristic(BleUuids.BATTERY_LEVEL_CHAR)?.let { targetChars.add(it) }

            try { gatt.device?.address?.let { saveDeviceAddress(it) } } catch (_: SecurityException) {}

            pendingSubscriptions.clear()
            pendingSubscriptions.addAll(targetChars)

            val deviceName = try { gatt.device?.name ?: "Scale" } catch (_: SecurityException) { "Scale" }
            connectedDeviceName = deviceName
            mainHandler.post { listener.onConnected(deviceName) }
            subscribeNext(gatt)
        }

        override fun onDescriptorWrite(gatt: BluetoothGatt, descriptor: BluetoothGattDescriptor, status: Int) {
            subscribeNext(gatt)
        }

        @Suppress("DEPRECATION")
        override fun onCharacteristicChanged(gatt: BluetoothGatt, characteristic: BluetoothGattCharacteristic) {
            val data = characteristic.value ?: return
            processCharacteristicData(characteristic, data)
        }

        override fun onCharacteristicChanged(gatt: BluetoothGatt, characteristic: BluetoothGattCharacteristic, value: ByteArray) {
            processCharacteristicData(characteristic, value)
        }

        @Suppress("DEPRECATION")
        override fun onCharacteristicRead(gatt: BluetoothGatt, characteristic: BluetoothGattCharacteristic, status: Int) {
            if (status == BluetoothGatt.GATT_SUCCESS && characteristic.uuid == BleUuids.BATTERY_LEVEL_CHAR) {
                val level = characteristic.value?.firstOrNull()?.toInt()?.and(0xFF)
                if (level != null) mainHandler.post { listener.onBatteryReceived(level) }
            }
        }
    }

    private fun subscribeNext(gatt: BluetoothGatt) {
        val char = pendingSubscriptions.removeFirstOrNull() ?: return
        if (char.uuid == BleUuids.BATTERY_LEVEL_CHAR) {
            try { gatt.readCharacteristic(char) } catch (_: SecurityException) {}
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
            val descriptor = char.getDescriptor(CCCD_UUID) ?: run { subscribeNext(gatt); return }
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
        if (char.uuid == BleUuids.BATTERY_LEVEL_CHAR && data.isNotEmpty()) {
            val level = data[0].toInt() and 0xFF
            mainHandler.post { listener.onBatteryReceived(level) }
            return
        }
        val reading = ScaleProtocol.parse(char, data)
        if (reading != null && reading.value > 0f) {
            mainHandler.post { listener.onWeightReceived(reading) }
        }
    }
}
