package it.dadaloop.evershelf.scalegate

import android.Manifest
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import it.dadaloop.evershelf.scalegate.databinding.ActivityMainBinding
import java.net.Inet4Address
import java.net.NetworkInterface
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private const val WS_PORT = 8765

class MainActivity : AppCompatActivity(), BleScaleListener, ServerEventListener {

    private lateinit var binding: ActivityMainBinding
    private lateinit var bleManager: BleScaleManager
    private var wsServer: GatewayWebSocketServer? = null

    private val devices = mutableListOf<BleDeviceInfo>()
    private lateinit var deviceAdapter: DeviceAdapter

    private var batteryLevel: Int? = null
    private val debugLines = mutableListOf<String>()
    private var debugVisible = false
    private var lastDebugUpdate = 0L
    private val debugTimeFmt = SimpleDateFormat("HH:mm:ss.SSS", Locale.getDefault())
    private companion object {
        const val MAX_DEBUG_LINES = 150
        const val DEBUG_THROTTLE_MS = 200L
    }

    // ─── Permission launcher ───────────────────────────────────────────────────

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { granted ->
        if (granted.values.all { it }) {
            startGatewayServer()
        } else {
            showDialog("Missing permissions",
                "The app requires Bluetooth and Location permissions to function.")
        }
    }

    private val enableBtLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode == RESULT_OK) checkPermissionsAndStart()
        else showDialog("Bluetooth required", "Please enable Bluetooth to use the gateway.")
    }

    // ─── Lifecycle ─────────────────────────────────────────────────────────────

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        bleManager = BleScaleManager(this, this)

        deviceAdapter = DeviceAdapter(devices) { info ->
            bleManager.connect(info.device)
        }
        binding.rvDevices.apply {
            layoutManager = LinearLayoutManager(this@MainActivity)
            adapter = deviceAdapter
        }

        binding.btnScan.setOnClickListener { startScanIfPermitted() }
        binding.btnDisconnect.setOnClickListener {
            bleManager.disconnect()
            updateUiDisconnected()
        }
        binding.btnDebug.setOnClickListener {
            debugVisible = !debugVisible
            binding.svDebugLog.visibility = if (debugVisible) View.VISIBLE else View.GONE
            binding.btnCopyLog.visibility = if (debugVisible) View.VISIBLE else View.GONE
            binding.btnShareLog.visibility = if (debugVisible) View.VISIBLE else View.GONE
            binding.btnDebug.text = if (debugVisible) "\uD83D\uDC1B Hide Debug" else "\uD83D\uDC1B Debug"
        }
        binding.btnCopyLog.setOnClickListener {
            val log = debugLines.joinToString("\n")
            val cm = getSystemService(CLIPBOARD_SERVICE) as android.content.ClipboardManager
            cm.setPrimaryClip(android.content.ClipData.newPlainText("EverShelf Scale Log", log))
            Toast.makeText(this, "Log copied to clipboard", Toast.LENGTH_SHORT).show()
        }
        binding.btnShareLog.setOnClickListener {
            val log = debugLines.joinToString("\n")
            val intent = Intent(Intent.ACTION_SEND).apply {
                type = "text/plain"
                putExtra(Intent.EXTRA_SUBJECT, "EverShelf Scale Gateway - Debug Log")
                putExtra(Intent.EXTRA_TEXT, log)
            }
            startActivity(Intent.createChooser(intent, "Share log"))
        }

        // Show app version
        try {
            val pInfo = packageManager.getPackageInfo(packageName, 0)
            binding.tvVersion.text = "v${pInfo.versionName} (${pInfo.longVersionCode})"
        } catch (_: Exception) { }

        updateGatewayUrl()
        checkPermissionsAndStart()

        // Auto-connect: if we have a saved device, start scanning with auto-connect enabled
        if (bleManager.getSavedDeviceAddress() != null) {
            binding.tvScanHint.visibility = View.VISIBLE
            binding.tvScanHint.text = "\uD83D\uDD04 Reconnecting to saved scale\u2026"
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        bleManager.disconnect()
        wsServer?.stop(1000)
    }

    // ─── Permissions & startup ─────────────────────────────────────────────────

    private fun checkPermissionsAndStart() {
        val required = buildList {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                add(Manifest.permission.BLUETOOTH_SCAN)
                add(Manifest.permission.BLUETOOTH_CONNECT)
            } else {
                add(Manifest.permission.ACCESS_FINE_LOCATION)
            }
        }
        val missing = required.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }
        when {
            missing.isNotEmpty() -> permissionLauncher.launch(missing.toTypedArray())
            !isBluetoothEnabled() -> enableBtLauncher.launch(Intent(BluetoothAdapter.ACTION_REQUEST_ENABLE))
            else -> startGatewayServer()
        }
    }

    private fun isBluetoothEnabled(): Boolean {
        val adapter = android.bluetooth.BluetoothManager::class.java.let {
            getSystemService(it)
        } as? android.bluetooth.BluetoothManager
        return adapter?.adapter?.isEnabled == true
    }

    private fun startScanIfPermitted() {
        if (!bleManager.hasRequiredPermissions()) {
            checkPermissionsAndStart()
            return
        }
        devices.clear()
        deviceAdapter.notifyDataSetChanged()
        debugLines.clear()
        binding.tvDebugLog.text = ""
        binding.tvScanHint.visibility = View.VISIBLE
        binding.tvScanHint.text = "Scanning for BLE scales\u2026"
        binding.btnScan.isEnabled = false
        bleManager.enableAutoConnect()
        bleManager.startScan()
    }

    // ─── WebSocket gateway ─────────────────────────────────────────────────────

    private fun startGatewayServer() {
        if (wsServer != null) return
        try {
            wsServer = GatewayWebSocketServer(WS_PORT, this)
            wsServer!!.start()
            updateGatewayUrl()
            binding.tvGatewayStatus.text = "\u2705 Gateway active on port $WS_PORT"
        } catch (e: Exception) {
            binding.tvGatewayStatus.text = "\u274C Failed to start gateway: ${e.message}"
        }

        // Auto-scan if there's a saved device
        if (bleManager.getSavedDeviceAddress() != null && bleManager.hasRequiredPermissions()) {
            bleManager.enableAutoConnect()
            bleManager.startScan()
        }
    }

    private fun updateGatewayUrl() {
        val ip = getLocalIpAddress() ?: "—"
        val url = "ws://$ip:$WS_PORT"
        binding.tvGatewayUrl.text = url
        binding.tvGatewayUrlHint.text = "Paste this URL in EverShelf \u2192 Settings \u2192 Smart Scale"
        binding.btnCopyUrl.setOnClickListener {
            val cm = getSystemService(CLIPBOARD_SERVICE) as android.content.ClipboardManager
            cm.setPrimaryClip(android.content.ClipData.newPlainText("EverShelf Gateway URL", url))
            binding.btnCopyUrl.text = "\u2705 Copied!"
            binding.btnCopyUrl.postDelayed({ binding.btnCopyUrl.text = "\uD83D\uDCCB Copy URL" }, 2000)
        }
    }

    // ─── BleScaleListener ─────────────────────────────────────────────────────

    override fun onDeviceFound(info: BleDeviceInfo) {
        if (devices.none { it.device.address == info.device.address }) {
            // Insert keeping descending scaleScore order (scale-likely devices first)
            val insertAt = devices.indexOfFirst { it.scaleScore < info.scaleScore }
                .let { if (it < 0) devices.size else it }
            devices.add(insertAt, info)
            deviceAdapter.notifyItemInserted(insertAt)
        }
    }

    override fun onConnecting(device: BluetoothDevice) {
        val name = try { device.name ?: device.address } catch (e: SecurityException) { device.address }
        binding.tvScaleStatus.text = "\u23f3 Connecting to $name\u2026"
        binding.tvWeight.text = "— — —"
        binding.cardConnection.setCardBackgroundColor(getColor(android.R.color.holo_orange_light))
    }

    override fun onConnected(deviceName: String) {
        binding.tvScaleStatus.text = "\u2705 Connected: $deviceName"
        binding.tvWeight.text = "Waiting for weight\u2026"
        binding.cardConnection.setCardBackgroundColor(getColor(android.R.color.holo_green_light))
        binding.btnDisconnect.visibility = View.VISIBLE
        binding.rvDevices.visibility = View.GONE
        binding.btnScan.visibility = View.GONE
        binding.tvScanHint.visibility = View.GONE
        wsServer?.publishStatus("connected", deviceName, batteryLevel)
    }

    override fun onDisconnected() {
        wsServer?.publishStatus("disconnected", null, null)
        updateUiDisconnected()
        // Auto-reconnect: if a saved device exists, restart scan after a short delay.
        // This handles the scale turning off by itself (auto-off) — when it powers
        // back on it will start advertising again and we will pick it up.
        if (bleManager.getSavedDeviceAddress() != null && bleManager.hasRequiredPermissions()) {
            binding.tvScanHint.visibility = View.VISIBLE
            binding.tvScanHint.text = "🔄 Reconnecting to saved scale in 5 s…"
            binding.root.postDelayed({
                if (!bleManager.isConnected) {
                    bleManager.enableAutoConnect()
                    bleManager.startScan()
                }
            }, 5_000L)
        }
    }

    override fun onWeightReceived(reading: WeightReading) {
        val displayValue = if (reading.value % 1f == 0f) reading.value.toInt().toString()
                           else "%.1f".format(reading.value)
        binding.tvWeight.text = "$displayValue ${reading.unit}"

        if (reading.stable) {
            binding.tvWeightHint.text = "\u2713 Stable reading"
        } else {
            binding.tvWeightHint.text = "\u23f3 Measuring\u2026"
        }
        wsServer?.publishWeight(reading.value, reading.unit, reading.stable, batteryLevel)
    }

    override fun onBatteryReceived(level: Int) {
        batteryLevel = level
        binding.tvBattery.text = "🔋 $level%"
        binding.tvBattery.visibility = View.VISIBLE
        wsServer?.publishStatus("connected", binding.tvScaleStatus.text.toString()
            .removePrefix("\u2705 Connected: "), level)
    }

    override fun onError(message: String) {
        binding.tvScaleStatus.text = "❌ $message"
        binding.cardConnection.setCardBackgroundColor(getColor(android.R.color.holo_red_light))
    }

    override fun onScanStopped() {
        binding.btnScan.isEnabled = true
        if (devices.isEmpty()) {
            binding.tvScanHint.text = "No scale found. Make sure it's on, then scan again."
        } else {
            binding.tvScanHint.text = "Tap a scale to connect."
        }
    }

    override fun onDebugEvent(message: String) {
        runOnUiThread {
            val ts = debugTimeFmt.format(Date())
            debugLines.add("[$ts] $message")
            // Keep only last MAX_DEBUG_LINES
            while (debugLines.size > MAX_DEBUG_LINES) debugLines.removeAt(0)
            // Throttle UI updates to avoid freezing
            val now = System.currentTimeMillis()
            if (now - lastDebugUpdate >= DEBUG_THROTTLE_MS) {
                lastDebugUpdate = now
                binding.tvDebugLog.text = debugLines.joinToString("\n")
                if (debugVisible) {
                    binding.svDebugLog.post { binding.svDebugLog.fullScroll(View.FOCUS_DOWN) }
                }
            }
        }
    }

    // ─── ServerEventListener ──────────────────────────────────────────────────

    override fun onClientConnected(address: String) {
        runOnUiThread {
            binding.tvClientCount.text = "\uD83C\uDF10 Client connected: $address"
            binding.tvClientCount.visibility = View.VISIBLE
        }
    }

    override fun onClientDisconnected(address: String) {
        runOnUiThread {
            binding.tvClientCount.visibility = View.GONE
        }
    }

    override fun onClientRequestedWeight() { /* Nothing extra needed */ }

    // ─── UI helpers ───────────────────────────────────────────────────────────

    private fun updateUiDisconnected() {
        binding.tvScaleStatus.text = "\u26a1 Ready \u2014 scan for a scale"
        binding.tvWeight.text = "— — —"
        binding.tvWeightHint.text = ""
        binding.tvBattery.visibility = View.GONE
        binding.cardConnection.setCardBackgroundColor(getColor(android.R.color.darker_gray))
        binding.btnDisconnect.visibility = View.GONE
        binding.rvDevices.visibility = View.VISIBLE
        binding.btnScan.visibility = View.VISIBLE
    }

    private fun getLocalIpAddress(): String? {
        return try {
            NetworkInterface.getNetworkInterfaces().toList()
                .flatMap { it.inetAddresses.toList() }
                .filterIsInstance<Inet4Address>()
                .firstOrNull { !it.isLoopbackAddress }
                ?.hostAddress
        } catch (e: Exception) { null }
    }

    private fun showDialog(title: String, message: String) {
        AlertDialog.Builder(this)
            .setTitle(title)
            .setMessage(message)
            .setPositiveButton("OK", null)
            .show()
    }

    // ─── RecyclerView adapter ──────────────────────────────────────────────────

    inner class DeviceAdapter(
        private val items: List<BleDeviceInfo>,
        private val onClick: (BleDeviceInfo) -> Unit,
    ) : RecyclerView.Adapter<DeviceAdapter.VH>() {

        inner class VH(view: View) : RecyclerView.ViewHolder(view) {
            val tvName: TextView = view.findViewById(R.id.tv_device_name)
            val tvAddr: TextView = view.findViewById(R.id.tv_device_addr)
            val tvRssi: TextView = view.findViewById(R.id.tv_device_rssi)
        }

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
            val view = LayoutInflater.from(parent.context)
                .inflate(R.layout.item_device, parent, false)
            return VH(view)
        }

        override fun onBindViewHolder(holder: VH, position: Int) {
            val info = items[position]
            holder.tvName.text = info.name
            holder.tvAddr.text = info.device.address
            holder.tvRssi.text = info.proximity
            holder.itemView.setOnClickListener { onClick(info) }
        }

        override fun getItemCount() = items.size
    }
}
