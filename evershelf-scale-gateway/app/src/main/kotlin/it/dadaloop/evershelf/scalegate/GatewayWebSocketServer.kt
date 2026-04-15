package it.dadaloop.evershelf.scalegate

import android.util.Log
import org.java_websocket.WebSocket
import org.java_websocket.handshake.ClientHandshake
import org.java_websocket.server.WebSocketServer
import org.json.JSONObject
import java.net.InetSocketAddress
import java.util.Collections

private const val TAG = "GatewayWsServer"

/**
 * Callbacks for the WebSocket server, dispatched on the server's internal thread.
 * The caller (MainActivity) is responsible for switching to the main thread if needed.
 */
interface ServerEventListener {
    fun onClientConnected(address: String)
    fun onClientDisconnected(address: String)
    fun onClientRequestedWeight()
}

/**
 * WebSocket server that exposes smart-scale data to EverShelf running in a browser.
 *
 * Message protocol (JSON):
 *
 * Server -> Client:
 *   {"type":"status","state":"connected"|"disconnected","device":"CK10G","battery":80}
 *   {"type":"weight","value":350,"unit":"g","stable":true,"timestamp":1712345678000}
 *   {"type":"pong"}
 *
 * Client → Server:
 *   {"type":"get_status"}   → server responds with current status message
 *   {"type":"get_weight"}   → server will push the next stable weight reading
 *   {"type":"ping"}         → server responds with {"type":"pong"}
 */
class GatewayWebSocketServer(
    port: Int,
    private val eventListener: ServerEventListener?,
) : WebSocketServer(InetSocketAddress(port)) {

    // Thread-safe set of clients waiting for the next stable weight reading
    private val pendingWeightRequests: MutableSet<WebSocket> =
        Collections.synchronizedSet(mutableSetOf())

    // Last known scale state (to send to new clients immediately)
    @Volatile private var lastStatusJson: String = buildStatusJson("disconnected", null, null)
    @Volatile private var lastWeightJson: String? = null

    // ─── Server lifecycle ──────────────────────────────────────────────────────

    override fun onStart() {
        Log.i(TAG, "WebSocket server started on port ${address.port}")
        connectionLostTimeout = 30
    }

    override fun onOpen(conn: WebSocket, handshake: ClientHandshake) {
        val addr = conn.remoteSocketAddress?.toString() ?: "?"
        Log.d(TAG, "Client connected: $addr")

        // Immediately send current status so the web app knows the scale state
        conn.send(lastStatusJson)
        lastWeightJson?.let { conn.send(it) }

        eventListener?.onClientConnected(addr)
    }

    override fun onClose(conn: WebSocket, code: Int, reason: String, remote: Boolean) {
        val addr = conn.remoteSocketAddress?.toString() ?: "?"
        Log.d(TAG, "Client disconnected: $addr (code=$code)")
        pendingWeightRequests.remove(conn)
        eventListener?.onClientDisconnected(addr)
    }

    override fun onMessage(conn: WebSocket, message: String) {
        try {
            val json = JSONObject(message)
            when (json.optString("type")) {
                "ping"       -> conn.send("""{"type":"pong"}""")
                "get_status" -> conn.send(lastStatusJson)
                "get_weight" -> {
                    // Add to pending set; next stable weight will be sent to this client
                    pendingWeightRequests.add(conn)
                    eventListener?.onClientRequestedWeight()
                    // If we already have a recent weight, send it immediately
                    lastWeightJson?.let { conn.send(it) }
                }
            }
        } catch (e: Exception) {
            Log.w(TAG, "Malformed message: $message")
        }
    }

    override fun onError(conn: WebSocket?, ex: Exception) {
        Log.e(TAG, "WebSocket error on ${conn?.remoteSocketAddress}", ex)
    }

    // ─── Publishing API ────────────────────────────────────────────────────────

    /**
     * Broadcast scale connection status to all connected WebSocket clients.
     */
    fun publishStatus(state: String, deviceName: String?, battery: Int?) {
        lastStatusJson = buildStatusJson(state, deviceName, battery)
        broadcast(lastStatusJson)
    }

    /**
     * Broadcast a weight reading to all clients.
     * If [stable] is true, also fulfil pending on-demand weight requests.
     */
    fun publishWeight(grams: Int, stable: Boolean, battery: Int? = null) {
        val json = buildWeightJson(grams, stable)
        lastWeightJson = json
        broadcast(json)

        if (stable) {
            synchronized(pendingWeightRequests) {
                // Clients that requested on-demand readings are already served by broadcast;
                // just clear the pending set.
                pendingWeightRequests.clear()
            }
        }
    }

    // ─── JSON builders ─────────────────────────────────────────────────────────

    private fun buildStatusJson(state: String, device: String?, battery: Int?): String {
        val obj = JSONObject()
        obj.put("type", "status")
        obj.put("state", state)
        if (device != null) obj.put("device", device)
        if (battery != null) obj.put("battery", battery)
        return obj.toString()
    }

    private fun buildWeightJson(grams: Int, stable: Boolean): String {
        val obj = JSONObject()
        obj.put("type", "weight")
        obj.put("value", grams)
        obj.put("unit", "g")
        obj.put("stable", stable)
        obj.put("timestamp", System.currentTimeMillis())
        return obj.toString()
    }
}
