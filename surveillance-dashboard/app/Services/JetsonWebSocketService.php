<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class JetsonWebSocketService
{
    /**
     * Check if a specific device is online.
     * $deviceId must be the real device id (e.g. 'rock1', 'jetson-2') —
     * there is no safe single-device default on a multi-device fleet.
     */
    public function isOnline(string $deviceId): bool
    {
        return (bool) Cache::get("jetson_ws_online_{$deviceId}", false);
    }

    /**
     * Send event to a specific device via the WebSocket outbound queue.
     * The event carries 'target_device' so every connected agent can ignore
     * commands that aren't addressed to it (the WS server broadcasts to all
     * open connections; filtering happens on each agent).
     */
    public function sendEvent(string $event, string $deviceId, array $data): void
    {
        $queue = Cache::get('ws_outbound_queue', []);
        $queue[] = [
            'event' => $event,
            'data'  => array_merge($data, ['target_device' => $deviceId]),
        ];
        Cache::put('ws_outbound_queue', $queue, 86400);
    }

    /**
     * Get connection info (cameras, version, last heartbeat) for a device.
     */
    public function getConnectionInfo(string $deviceId): array
    {
        return [
            'online' => $this->isOnline($deviceId),
            'cameras' => Cache::get("jetson_ws_cameras_{$deviceId}", []),
            'version' => Cache::get("jetson_ws_version_{$deviceId}", 'unknown'),
            'last_heartbeat' => Cache::get("jetson_ws_last_heartbeat_{$deviceId}"),
        ];
    }

    /**
     * Mark a device as online (HTTP polling fallback path).
     * Callers that don't know which device made the request (e.g. the
     * shared-token PTZ/settings poll endpoints) may omit $deviceId; this
     * only feeds the legacy HTTP-fallback online indicator, not any
     * targeted command, so an ambiguous default here is harmless.
     */
    public function markOnline(\Illuminate\Http\Request $request, string $deviceId = 'rock1'): void
    {
        Cache::put("jetson_ws_online_{$deviceId}", true, 15); // short TTL for polling fallback
        Cache::put("jetson_ws_last_heartbeat_{$deviceId}", now()->timestamp, 15);

        if ($request->hasHeader('X-Cameras')) {
            $cameras = explode(',', $request->header('X-Cameras'));
            Cache::put("jetson_ws_cameras_{$deviceId}", $cameras, 86400);
        }
        if ($request->hasHeader('X-Version')) {
            Cache::put("jetson_ws_version_{$deviceId}", $request->header('X-Version'), 86400);
        }
    }

    /**
     * Send PTZ command via WS.
     * NOTE: $cameraId here is the dashboard's composite id (e.g. "rock1-cam1").
     * The device's own CAMERAS dict on the agent only knows the raw id
     * ("cam1"), so this already never matches across devices — see
     * KNOWN_ISSUES.md for the follow-up to normalize this properly.
     */
    public function sendPtzCommand(string $deviceId, string $cameraId, string $commandId, string $action, int $speed): void
    {
        $this->sendEvent('ptz.command', $deviceId, [
            'camera_id' => $cameraId,
            'command_id' => $commandId,
            'action' => $action,
            'speed' => $speed
        ]);
    }

    /**
     * Send settings update.
     */
    public function sendSettingsUpdate(string $deviceId, array $cameras): void
    {
        $this->sendEvent('settings.update', $deviceId, [
            'cameras' => $cameras
        ]);
    }

    /**
     * Send diagnostic start command to a specific device.
     */
    public function sendDiagnosticStart(string $deviceId, string $requestId, array $checks = ['cameras', 'streams', 'tunnel', 'logs']): void
    {
        $this->sendEvent('diagnostic.start', $deviceId, [
            'checks' => $checks,
            'request_id' => $requestId
        ]);
    }

    /**
     * Send sync start command to a specific device.
     * VPS config contains: upload_url, token
     */
    public function sendSyncStart(string $deviceId, string $requestId, array $vpsConfig, array $options): void
    {
        $this->sendEvent('sync.start', $deviceId, [
            'request_id' => $requestId,
            'vps' => $vpsConfig,
            'options' => $options
        ]);
    }

    /**
     * Send sync list files command to a specific device.
     */
    public function sendSyncListFiles(string $deviceId, string $requestId, array $options): void
    {
        $this->sendEvent('sync.list_files', $deviceId, [
            'request_id' => $requestId,
            'options' => $options
        ]);
    }

    /**
     * Poll cache for a response to an event with a timeout.
     */
    public function getEventResponse(string $event, string $requestId, float $timeout = 5.0): ?array
    {
        $cacheKey = "ws_response_{$event}_{$requestId}";
        $elapsed = 0.0;
        $interval = 0.1; // 100ms

        while ($elapsed < $timeout) {
            $data = Cache::get($cacheKey);
            if ($data !== null) {
                // Keep the response or clear it? Better to clean up cache
                Cache::forget($cacheKey);
                return $data;
            }
            usleep($interval * 1000000);
            $elapsed += $interval;
        }

        return null;
    }

    /**
     * Send reboot command to a specific device.
     */
    public function sendReboot(string $deviceId): void
    {
        $this->sendEvent('jetson.reboot', $deviceId, []);
    }
}
