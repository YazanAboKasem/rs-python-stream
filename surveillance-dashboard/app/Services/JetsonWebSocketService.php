<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class JetsonWebSocketService
{
    /**
     * Check if Jetson is online.
     */
    public function isOnline(): bool
    {
        return (bool) Cache::get('jetson_ws_online', false);
    }

    /**
     * Send event to Jetson via WebSocket outbound queue.
     */
    public function sendEvent(string $event, array $data): void
    {
        $queue = Cache::get('ws_outbound_queue', []);
        $queue[] = [
            'event' => $event,
            'data' => $data
        ];
        Cache::put('ws_outbound_queue', $queue, 86400);
    }

    /**
     * Get Connection info (cameras, version, last heartbeat).
     */
    public function getConnectionInfo(): array
    {
        return [
            'online' => $this->isOnline(),
            'cameras' => Cache::get('jetson_ws_cameras', []),
            'version' => Cache::get('jetson_ws_version', 'unknown'),
            'last_heartbeat' => Cache::get('jetson_ws_last_heartbeat'),
        ];
    }

    /**
     * Mark Jetson as online (called from HTTP requests).
     */
    public function markOnline(\Illuminate\Http\Request $request): void
    {
        Cache::put('jetson_ws_online', true, 15); // short TTL for polling fallback
        Cache::put('jetson_ws_last_heartbeat', now()->timestamp, 15);

        if ($request->hasHeader('X-Cameras')) {
            $cameras = explode(',', $request->header('X-Cameras'));
            Cache::put('jetson_ws_cameras', $cameras, 86400);
        }
        if ($request->hasHeader('X-Version')) {
            Cache::put('jetson_ws_version', $request->header('X-Version'), 86400);
        }
    }

    /**
     * Send PTZ command via WS.
     */
    public function sendPtzCommand(string $cameraId, string $commandId, string $action, int $speed): void
    {
        $this->sendEvent('ptz.command', [
            'camera_id' => $cameraId,
            'command_id' => $commandId,
            'action' => $action,
            'speed' => $speed
        ]);
    }

    /**
     * Send settings update.
     */
    public function sendSettingsUpdate(array $cameras): void
    {
        $this->sendEvent('settings.update', [
            'cameras' => $cameras
        ]);
    }

    /**
     * Send diagnostic start command.
     */
    public function sendDiagnosticStart(string $requestId, array $checks = ['cameras', 'streams', 'tunnel', 'logs']): void
    {
        $this->sendEvent('diagnostic.start', [
            'checks' => $checks,
            'request_id' => $requestId
        ]);
    }

    /**
     * Send sync start command.
     * VPS config contains: upload_url, token
     */
    public function sendSyncStart(string $requestId, array $vpsConfig, array $options): void
    {
        $this->sendEvent('sync.start', [
            'request_id' => $requestId,
            'vps' => $vpsConfig,
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
     * Send reboot command.
     */
    public function sendReboot(): void
    {
        $this->sendEvent('jetson.reboot', []);
    }
}
