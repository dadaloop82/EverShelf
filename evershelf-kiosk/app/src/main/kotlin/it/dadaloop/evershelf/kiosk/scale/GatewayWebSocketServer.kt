package it.dadaloop.evershelf.kiosk.scale

import android.util.Log
import org.java_websocket.WebSocket
import org.java_websocket.handshake.ClientHandshake
import org.java_websocket.server.WebSocketServer
import org.json.JSONObject
import java.net.InetSocketAddress
import java.util.Collections

private const val TAG = "GatewayWsServer"

interface ServerEventListener {
    fun onClientConnected(address: String)
    fun onClientDisconnected(address: String)
    fun onClientRequestedWeight()
}

/**
 * WebSocket server that exposes BLE scale data to EverShelf running in a browser.
 * Protocol is identical to the standalone gateway app so the webapp JS needs no changes.
 */
class GatewayWebSocketServer(
    port: Int,
    private val eventListener: ServerEventListener?,
) : WebSocketServer(InetSocketAddress(port)) {

    private val pendingWeightRequests: MutableSet<WebSocket> =
        Collections.synchronizedSet(mutableSetOf())

    @Volatile private var lastStatusJson: String = buildStatusJson("disconnected", null, null)
    @Volatile private var lastWeightJson: String? = null

    override fun onStart() {
        Log.i(TAG, "WebSocket server started on port ${address.port}")
        connectionLostTimeout = 30
    }

    override fun onOpen(conn: WebSocket, handshake: ClientHandshake) {
        conn.send(lastStatusJson)
        lastWeightJson?.let { conn.send(it) }
        eventListener?.onClientConnected(conn.remoteSocketAddress?.toString() ?: "?")
    }

    override fun onClose(conn: WebSocket, code: Int, reason: String, remote: Boolean) {
        pendingWeightRequests.remove(conn)
        eventListener?.onClientDisconnected(conn.remoteSocketAddress?.toString() ?: "?")
    }

    override fun onMessage(conn: WebSocket, message: String) {
        try {
            when (JSONObject(message).optString("type")) {
                "ping"       -> conn.send("""{"type":"pong"}""")
                "get_status" -> conn.send(lastStatusJson)
                "get_weight" -> {
                    pendingWeightRequests.add(conn)
                    eventListener?.onClientRequestedWeight()
                    lastWeightJson?.let { conn.send(it) }
                }
            }
        } catch (_: Exception) {}
    }

    override fun onError(conn: WebSocket?, ex: Exception) {
        Log.e(TAG, "WebSocket error", ex)
    }

    fun publishStatus(state: String, deviceName: String?, battery: Int?) {
        lastStatusJson = buildStatusJson(state, deviceName, battery)
        broadcast(lastStatusJson)
    }

    fun publishWeight(value: Float, unit: String, stable: Boolean, battery: Int? = null) {
        val json = buildWeightJson(value, unit, stable)
        lastWeightJson = json
        broadcast(json)
        if (stable) synchronized(pendingWeightRequests) { pendingWeightRequests.clear() }
    }

    private fun buildStatusJson(state: String, device: String?, battery: Int?): String {
        val obj = JSONObject()
        obj.put("type", "status")
        obj.put("state", state)
        if (device != null) obj.put("device", device)
        if (battery != null) obj.put("battery", battery)
        return obj.toString()
    }

    private fun buildWeightJson(value: Float, unit: String, stable: Boolean): String {
        val obj = JSONObject()
        obj.put("type", "weight")
        obj.put("value", Math.round(value * 10f) / 10.0)
        obj.put("unit", unit)
        obj.put("stable", stable)
        obj.put("timestamp", System.currentTimeMillis())
        return obj.toString()
    }
}
