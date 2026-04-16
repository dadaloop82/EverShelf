package it.dadaloop.evershelf.kiosk

import android.app.*
import android.bluetooth.BluetoothDevice
import android.content.Context
import android.content.Intent
import android.os.Binder
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.util.Log
import androidx.core.app.NotificationCompat

private const val TAG = "ScaleGtwService"
private const val CHANNEL_ID = "scale_gateway"
private const val NOTIFICATION_ID = 1
private const val WS_PORT = 8765
private const val RECONNECT_DELAY_MS = 5000L

class ScaleGatewayService : Service(), BleScaleListener, ServerEventListener {

    private var bleManager: BleScaleManager? = null
    private var wsServer: GatewayWebSocketServer? = null
    private var lastBattery: Int? = null
    private var connectedDeviceName: String? = null
    private val mainHandler = Handler(Looper.getMainLooper())

    // Binder so KioskActivity can get status updates
    inner class LocalBinder : Binder() {
        fun getService(): ScaleGatewayService = this@ScaleGatewayService
    }
    private val binder = LocalBinder()

    // Callbacks for the activity
    var statusCallback: ((String, String?, Int?) -> Unit)? = null // state, device, battery
    var weightCallback: ((Float, String, Boolean) -> Unit)? = null // value, unit, stable

    override fun onBind(intent: Intent?): IBinder = binder

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        startForeground(NOTIFICATION_ID, buildNotification("Starting..."))

        // Start WebSocket server
        wsServer = GatewayWebSocketServer(WS_PORT, this).also {
            try { it.start() } catch (e: Exception) {
                Log.e(TAG, "Failed to start WS server", e)
            }
        }

        // Start BLE manager
        bleManager = BleScaleManager(this, this).also {
            if (it.hasRequiredPermissions()) {
                it.enableAutoConnect()
                it.startScan()
            }
        }
    }

    override fun onDestroy() {
        bleManager?.disconnect()
        bleManager?.stopScan()
        try { wsServer?.stop(1000) } catch (_: Exception) {}
        super.onDestroy()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        return START_STICKY
    }

    fun startScaleScan() {
        bleManager?.let {
            if (it.hasRequiredPermissions()) {
                it.enableAutoConnect()
                it.startScan()
            }
        }
    }

    fun disconnectScale() {
        bleManager?.disconnect()
        connectedDeviceName = null
        wsServer?.publishStatus("disconnected", null, null)
        updateNotification("Gateway active — no scale")
        statusCallback?.invoke("disconnected", null, null)
    }

    fun connectDevice(device: BluetoothDevice) {
        bleManager?.connect(device)
    }

    val isScaleConnected: Boolean get() = bleManager?.isConnected == true

    // ─── BleScaleListener ──────────────────────────────────────────────────

    override fun onDeviceFound(info: BleDeviceInfo) {}
    override fun onConnecting(device: BluetoothDevice) {
        updateNotification("Connecting...")
        statusCallback?.invoke("connecting", null, null)
    }

    override fun onConnected(deviceName: String) {
        connectedDeviceName = deviceName
        wsServer?.publishStatus("connected", deviceName, lastBattery)
        updateNotification("Connected: $deviceName")
        statusCallback?.invoke("connected", deviceName, lastBattery)
    }

    override fun onDisconnected() {
        connectedDeviceName = null
        wsServer?.publishStatus("disconnected", null, null)
        updateNotification("Scale disconnected — reconnecting...")
        statusCallback?.invoke("disconnected", null, null)
        // Auto-reconnect
        mainHandler.postDelayed({
            bleManager?.let {
                if (!it.isConnected && it.hasRequiredPermissions()) {
                    it.enableAutoConnect()
                    it.startScan()
                }
            }
        }, RECONNECT_DELAY_MS)
    }

    override fun onWeightReceived(reading: WeightReading) {
        wsServer?.publishWeight(reading.value, reading.unit, reading.stable, lastBattery)
        weightCallback?.invoke(reading.value, reading.unit, reading.stable)
    }

    override fun onBatteryReceived(level: Int) {
        lastBattery = level
        wsServer?.publishStatus("connected", connectedDeviceName, level)
    }

    override fun onError(message: String) {
        Log.w(TAG, "BLE error: $message")
    }

    override fun onScanStopped() {}
    override fun onDebugEvent(message: String) {}

    // ─── ServerEventListener ───────────────────────────────────────────────

    override fun onClientConnected(address: String) {
        Log.d(TAG, "WS client connected: $address")
    }

    override fun onClientDisconnected(address: String) {
        Log.d(TAG, "WS client disconnected: $address")
    }

    override fun onClientRequestedWeight() {}

    // ─── Notification ──────────────────────────────────────────────────────

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "Scale Gateway",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "EverShelf Scale Gateway running"
                setShowBadge(false)
            }
            (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager)
                .createNotificationChannel(channel)
        }
    }

    private fun buildNotification(text: String): Notification {
        val intent = Intent(this, KioskActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP
        }
        val pendingIntent = PendingIntent.getActivity(
            this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("EverShelf Gateway")
            .setContentText(text)
            .setSmallIcon(android.R.drawable.stat_sys_data_bluetooth)
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .build()
    }

    private fun updateNotification(text: String) {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        nm.notify(NOTIFICATION_ID, buildNotification(text))
    }
}
