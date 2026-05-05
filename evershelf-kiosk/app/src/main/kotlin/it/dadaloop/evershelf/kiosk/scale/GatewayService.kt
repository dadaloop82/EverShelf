package it.dadaloop.evershelf.kiosk.scale

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.bluetooth.BluetoothDevice
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.util.Log
import it.dadaloop.evershelf.kiosk.KioskActivity
import it.dadaloop.evershelf.kiosk.R

private const val TAG = "GatewayService"
private const val WS_PORT = 8765
private const val NOTIF_ID = 1001
private const val CHANNEL_ID = "evershelf_gateway"
private const val RECONNECT_DELAY_MS = 8_000L

/**
 * Foreground service that keeps the BLE scale connection and WebSocket server alive
 * independently of the KioskActivity lifecycle.
 *
 * The WebSocket server on port 8765 is protocol-compatible with the standalone
 * evershelf-scale-gateway app, so the EverShelf webapp JS needs no changes.
 */
class GatewayService : Service(), BleScaleListener, ServerEventListener {

    private lateinit var bleManager: BleScaleManager
    private var wsServer: GatewayWebSocketServer? = null
    private val handler = Handler(Looper.getMainLooper())
    private var connectedDeviceName: String? = null
    private var batteryLevel: Int? = null
    private var reconnectPending = false

    companion object {
        const val ACTION_START = "evershelf.gateway.START"
        const val ACTION_STOP  = "evershelf.gateway.STOP"

        /** Returns true if the service can try to connect (BLE permissions ok, device saved). */
        fun canStart(context: Context): Boolean {
            val prefs = context.getSharedPreferences("evershelf_kiosk", Context.MODE_PRIVATE)
            val hasScale  = prefs.getBoolean("has_scale", false)
            val hasDevice = prefs.getString("scale_device_address", null) != null
            return hasScale && hasDevice
        }

        fun start(context: Context) {
            val intent = Intent(context, GatewayService::class.java).apply {
                action = ACTION_START
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        fun stop(context: Context) {
            context.startService(Intent(context, GatewayService::class.java).apply {
                action = ACTION_STOP
            })
        }
    }

    override fun onCreate() {
        super.onCreate()
        bleManager = BleScaleManager(this, this)
        createNotificationChannel()
        startForeground(NOTIF_ID, buildNotification("Avvio bilancia…"))
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                stopSelf()
                return START_NOT_STICKY
            }
            else -> {
                startWsServer()
                connectToSavedScale()
            }
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        handler.removeCallbacksAndMessages(null)
        bleManager.disconnect()
        try { wsServer?.stop(1000) } catch (_: Exception) {}
        wsServer = null
        super.onDestroy()
    }

    // ── WebSocket server ──────────────────────────────────────────────────────

    private fun startWsServer() {
        if (wsServer != null) return
        try {
            wsServer = GatewayWebSocketServer(WS_PORT, this)
            wsServer!!.isReuseAddr = true
            wsServer!!.start()
            Log.i(TAG, "WebSocket server started on :$WS_PORT")
        } catch (e: Exception) {
            Log.e(TAG, "Failed to start WebSocket server", e)
            updateNotification("⚠️ WebSocket non avviato: ${e.message}")
        }
    }

    // ── BLE connection ────────────────────────────────────────────────────────

    private fun connectToSavedScale() {
        if (!bleManager.hasRequiredPermissions()) {
            updateNotification("⚠️ Permessi Bluetooth mancanti")
            return
        }
        val addr = bleManager.getSavedDeviceAddress() ?: run {
            updateNotification("Nessuna bilancia configurata")
            return
        }
        val name = bleManager.getSavedDeviceName() ?: addr
        updateNotification("🔍 Connessione a $name…")
        // Enable auto-connect: the scan callback will connect when the saved device is found
        bleManager.enableAutoConnect()
        bleManager.startScan()
    }

    private fun scheduleReconnect() {
        if (reconnectPending) return
        reconnectPending = true
        handler.postDelayed({
            reconnectPending = false
            if (bleManager.getSavedDeviceAddress() != null) {
                updateNotification("🔄 Riconnessione bilancia…")
                bleManager.enableAutoConnect()
                bleManager.startScan()
            }
        }, RECONNECT_DELAY_MS)
    }

    // ── BleScaleListener ─────────────────────────────────────────────────────

    override fun onDeviceFound(info: BleDeviceInfo) { /* handled by autoConnect */ }

    override fun onConnecting(device: BluetoothDevice) {
        val name = try { device.name ?: device.address } catch (_: SecurityException) { device.address }
        updateNotification("⏳ Connessione a $name…")
    }

    override fun onConnected(deviceName: String) {
        connectedDeviceName = deviceName
        updateNotification("✅ $deviceName connessa")
        wsServer?.publishStatus("connected", deviceName, batteryLevel)
        Log.i(TAG, "BLE scale connected: $deviceName")
    }

    override fun onDisconnected() {
        val name = connectedDeviceName ?: "bilancia"
        connectedDeviceName = null
        updateNotification("⚠️ $name disconnessa — riconnessione…")
        wsServer?.publishStatus("disconnected", null, null)
        scheduleReconnect()
    }

    override fun onWeightReceived(reading: WeightReading) {
        wsServer?.publishWeight(reading.value, reading.unit, reading.stable, batteryLevel)
    }

    override fun onBatteryReceived(level: Int) {
        batteryLevel = level
        connectedDeviceName?.let { wsServer?.publishStatus("connected", it, level) }
    }

    override fun onError(message: String) {
        Log.w(TAG, "BLE error: $message")
        scheduleReconnect()
    }

    override fun onScanStopped() { /* auto-reconnect handles retries */ }

    override fun onDebugEvent(message: String) {
        Log.d(TAG, message)
    }

    // ── ServerEventListener ───────────────────────────────────────────────────

    override fun onClientConnected(address: String) {
        Log.d(TAG, "WS client connected: $address")
    }

    override fun onClientDisconnected(address: String) {
        Log.d(TAG, "WS client disconnected: $address")
    }

    override fun onClientRequestedWeight() { /* weight is pushed via onWeightReceived */ }

    // ── Notification ──────────────────────────────────────────────────────────

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "EverShelf Scale Gateway",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Bilancia smart integrata"
                setShowBadge(false)
            }
            (getSystemService(NOTIFICATION_SERVICE) as NotificationManager)
                .createNotificationChannel(channel)
        }
    }

    private fun buildNotification(text: String): Notification {
        val pendingIntent = PendingIntent.getActivity(
            this, 0,
            Intent(this, KioskActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE
        )
        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Notification.Builder(this, CHANNEL_ID)
        } else {
            @Suppress("DEPRECATION")
            Notification.Builder(this)
        }
        return builder
            .setContentTitle("EverShelf Scale")
            .setContentText(text)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .build()
    }

    private fun updateNotification(text: String) {
        val nm = getSystemService(NOTIFICATION_SERVICE) as NotificationManager
        nm.notify(NOTIF_ID, buildNotification(text))
    }
}
