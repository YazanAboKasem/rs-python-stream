<?php

namespace App\WebSocket;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class JetsonWebSocketHandler
{
    private $connections = [];
    private $token;

    public function __construct()
    {
        $this->token = config('surveillance.api_token');
    }

    /**
     * Handle incoming raw data from a connection.
     * Returns string of response data to write back, or null.
     */
    public function handleData($connId, $data, &$connectionState)
    {
        if (empty($connectionState['handshake'])) {
            return $this->doHandshake($connId, $data, $connectionState);
        }

        // Buffer incoming data
        $connectionState['buffer'] .= $data;
        $responses = '';

        while (true) {
            $frame = $this->decodeFrame($connectionState['buffer']);
            if (!$frame) {
                break;
            }

            // Remove processed bytes from buffer
            $connectionState['buffer'] = substr($connectionState['buffer'], $frame['raw_length']);

            $opcode = $frame['opcode'];
            $payload = $frame['payload'];

            if ($opcode === 0x08) { // Connection Close
                $responses .= $this->encodeFrame('', 0x08);
                $connectionState['closed'] = true;
                break;
            } elseif ($opcode === 0x09) { // Ping
                $responses .= $this->encodeFrame($payload, 0x0A); // Pong
            } elseif ($opcode === 0x01 || $opcode === 0x02) { // Text or Binary
                $this->handleMessage($connId, $payload);
            }
        }

        return $responses !== '' ? $responses : null;
    }

    /**
     * Handle disconnect
     */
    public function handleDisconnect($connId)
    {
        Log::info("[WebSocket] Connection closed: {$connId}");
        
        $deviceId = $this->connections[$connId]['device_id'] ?? 'jetson-1';
        $this->recordShutdown($deviceId, 'Normal disconnect');
        Cache::put("jetson_ws_online_{$deviceId}", false, 86400);

        unset($this->connections[$connId]);

        // If no active connections left, mark Jetson offline
        if (empty($this->connections)) {
            Cache::put('jetson_ws_online', false, 86400);
            Log::info("[WebSocket] Jetson marked offline.");
        }
    }

    /**
     * Send event to all authenticated connections
     */
    public function broadcastEvent($event, $data)
    {
        $payload = json_encode([
            'event' => $event,
            'data' => $data
        ]);
        $frame = $this->encodeFrame($payload);

        $sentCount = 0;
        foreach ($this->connections as $connId => $conn) {
            try {
                $conn['stream']->write($frame);
                $sentCount++;
            } catch (\Exception $e) {
                Log::error("[WebSocket] Failed to write to connection {$connId}: " . $e->getMessage());
            }
        }
        return $sentCount;
    }

    /**
     * Perform HTTP handshake & validate Authorization
     */
    private function doHandshake($connId, $data, &$connectionState)
    {
        $lines = explode("\r\n", $data);
        $requestLine = array_shift($lines);
        
        // Parse HTTP request headers
        $headers = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (strpos($line, ':') !== false) {
                list($key, $val) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($val);
            }
        }

        // Validate HTTP Request Method and Upgrade header
        if (!isset($headers['upgrade']) || strtolower($headers['upgrade']) !== 'websocket') {
            Log::warning("[WebSocket] Connection {$connId} did not request WebSocket Upgrade.");
            $connectionState['closed'] = true;
            return "HTTP/1.1 400 Bad Request\r\n\r\n";
        }

        // Parse query string for token
        $requestPath = '';
        if (preg_match('/GET\s+(\S+)\s+HTTP/', $requestLine, $matches)) {
            $requestPath = $matches[1];
        }
        
        $tokenQuery = '';
        if ($requestPath && strpos($requestPath, '?') !== false) {
            list($path, $query) = explode('?', $requestPath, 2);
            parse_str($query, $queryParams);
            $tokenQuery = $queryParams['token'] ?? '';
        }

        // Extract Authorization header
        $authHeader = $headers['authorization'] ?? '';
        $tokenHeader = '';
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $tokenHeader = $matches[1];
        }

        $receivedToken = $tokenHeader ?: $tokenQuery;

        // Verify token
        if (empty($this->token) || $receivedToken !== $this->token) {
            Log::warning("[WebSocket] Connection {$connId} unauthorized. Invalid token.");
            $connectionState['closed'] = true;
            return "HTTP/1.1 401 Unauthorized\r\nContent-Type: text/plain\r\n\r\nUnauthorized";
        }

        // Complete the handshake
        $secKey = $headers['sec-websocket-key'] ?? '';
        if (empty($secKey)) {
            Log::warning("[WebSocket] Sec-WebSocket-Key missing.");
            $connectionState['closed'] = true;
            return "HTTP/1.1 400 Bad Request\r\n\r\n";
        }

        $acceptKey = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                    "Upgrade: websocket\r\n" .
                    "Connection: Upgrade\r\n" .
                    "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";

        $connectionState['handshake'] = true;
        $this->connections[$connId] = [
            'stream' => $connectionState['stream'],
            'headers' => $headers
        ];

        Log::info("[WebSocket] Connection {$connId} handshake successful.");
        return $response;
    }

    /**
     * Decode standard WebSocket frame
     */
    private function decodeFrame($data)
    {
        if (strlen($data) < 2) return null;
        $b0 = ord($data[0]);
        $b1 = ord($data[1]);
        $opcode = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0;
        $length = $b1 & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($data) < 4) return null;
            $length = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($data) < 10) return null;
            $length = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            if (strlen($data) < $offset + 4) return null;
            $mask = substr($data, $offset, 4);
            $offset += 4;
        } else {
            $mask = null;
        }

        if (strlen($data) < $offset + $length) return null;
        $payload = substr($data, $offset, $length);

        if ($masked && $mask !== null) {
            $decoded = '';
            for ($i = 0; $i < $length; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }

        return [
            'opcode' => $opcode,
            'payload' => $payload,
            'raw_length' => $offset + $length
        ];
    }

    /**
     * Encode payload into WebSocket frame
     */
    public function encodeFrame($payload, $opcode = 0x01)
    {
        $length = strlen($payload);
        $b0 = 0x80 | ($opcode & 0x0F); // FIN bit set

        if ($length <= 125) {
            $header = pack('C2', $b0, $length);
        } elseif ($length <= 65535) {
            $header = pack('C2n', $b0, 126, $length);
        } else {
            $header = pack('C2J', $b0, 127, $length);
        }

        return $header . $payload;
    }

    /**
     * Process message
     */
    private function handleMessage($connId, $payload)
    {
        Log::debug("[WebSocket] Received from {$connId}: {$payload}");
        try {
            $message = json_decode($payload, true);
        } catch (\Exception $e) {
            Log::error("[WebSocket] Failed to parse JSON: " . $e->getMessage());
            return;
        }

        if (!$message || !isset($message['event'])) {
            Log::warning("[WebSocket] Invalid message format received.");
            return;
        }

        $event = $message['event'];
        $data = $message['data'] ?? [];

        Log::info("[WebSocket] Event received: {$event}");

        switch ($event) {
            case 'jetson.hello':
                $deviceId = $data['jetson_name'] ?? 'jetson-1';
                if (isset($this->connections[$connId])) {
                    $this->connections[$connId]['device_id'] = $deviceId;
                }
                Cache::put("jetson_ws_online_{$deviceId}", true, 86400);
                Cache::put('jetson_ws_online', true, 86400);
                Cache::put("jetson_ws_cameras_{$deviceId}", $data['cameras'] ?? [], 86400);
                Cache::put('jetson_ws_cameras', $data['cameras'] ?? [], 86400);
                Cache::put("jetson_ws_version_{$deviceId}", $data['version'] ?? 'unknown', 86400);
                Cache::put("jetson_ws_last_heartbeat_{$deviceId}", now()->timestamp, 86400);
                Cache::put('jetson_ws_last_heartbeat', now()->timestamp, 86400);
                Log::info("[WebSocket] Jetson {$deviceId} logged in.", $data);
                $this->recordStartup($deviceId);
                break;

            case 'heartbeat':
                $deviceId = $this->connections[$connId]['device_id'] ?? 'jetson-1';
                Cache::put("jetson_ws_online_{$deviceId}", true, 86400);
                Cache::put('jetson_ws_online', true, 86400);
                Cache::put("jetson_ws_last_heartbeat_{$deviceId}", now()->timestamp, 86400);
                Cache::put('jetson_ws_last_heartbeat', now()->timestamp, 86400);
                
                // Record startup if no active log
                $latestLog = \App\Models\DevicePowerLog::where('device_id', $deviceId)
                    ->orderBy('id', 'desc')
                    ->first();
                if (!$latestLog || !is_null($latestLog->stopped_at)) {
                    $this->recordStartup($deviceId);
                }
                break;

            // Handle ACKs and other events
            case 'ptz.command.ack':
            case 'settings.update.ack':
            case 'diagnostic.camera_status':
            case 'diagnostic.stream_status':
            case 'diagnostic.tunnel_status':
            case 'diagnostic.logs':
            case 'sync.start.ack':
            case 'sync.progress':
            case 'sync.complete':
            case 'sync.pause.ack':
            case 'sync.resume.ack':
            case 'sync.cancel.ack':
                $requestId = $data['request_id'] ?? 'default';
                Cache::put("ws_response_{$event}_{$requestId}", $data, 300);
                Cache::put("ws_last_{$event}", $data, 86400);
                Log::info("[WebSocket] Stashed event {$event} response for ID: {$requestId}");
                break;

            default:
                Log::warning("[WebSocket] Unhandled event: {$event}");
                break;
        }
    }

    /**
     * Record device startup to DB
     */
    private function recordStartup($deviceId)
    {
        try {
            // Close any existing open log entry for this device
            \App\Models\DevicePowerLog::where('device_id', $deviceId)
                ->whereNull('stopped_at')
                ->update([
                    'stopped_at' => now(),
                    'reason' => 'Unexpected disconnect (reconnection)',
                ]);

            // Create a new start log entry
            \App\Models\DevicePowerLog::create([
                'device_id' => $deviceId,
                'started_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("[WebSocket] Failed to record startup for {$deviceId}: " . $e->getMessage());
        }
    }

    /**
     * Record device shutdown to DB
     */
    private function recordShutdown($deviceId, $reason = 'Disconnected')
    {
        try {
            \App\Models\DevicePowerLog::where('device_id', $deviceId)
                ->whereNull('stopped_at')
                ->update([
                    'stopped_at' => now(),
                    'reason' => $reason,
                ]);
        } catch (\Exception $e) {
            Log::error("[WebSocket] Failed to record shutdown for {$deviceId}: " . $e->getMessage());
        }
    }
}
