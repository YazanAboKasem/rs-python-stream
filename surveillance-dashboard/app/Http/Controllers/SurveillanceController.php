<?php

namespace App\Http\Controllers;

use App\Http\Controllers\TunnelController;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SurveillanceController extends Controller
{
    /**
     * Display the live surveillance dashboard (monitoring room).
     * Shows ALL cameras from ALL enabled devices, grouped by device.
     */
    public function index(): View
    {
        $devices = $this->resolveAllDevices();

        return view('surveillance.index', compact('devices'));
    }

    /**
     * Display the devices management page.
     */
    public function devices(): View
    {
        $devices = $this->resolveAllDevices();

        return view('surveillance.devices', compact('devices'));
    }

    /**
     * Display settings page for a specific device.
     */
    public function deviceSettings(string $deviceId): View
    {
        $devices = $this->resolveAllDevices();
        $device = $devices->firstWhere('id', $deviceId);

        if (!$device) {
            abort(404, "Device {$deviceId} not found");
        }

        return view('surveillance.device-settings', compact('device', 'devices'));
    }

    /**
     * Resolve all enabled devices with their cameras and stream URLs.
     */
    private function resolveAllDevices()
    {
        $deviceConfigs = config('surveillance.devices', []);

        // Backward compatibility: if no devices, wrap legacy cameras as single device
        if (empty($deviceConfigs)) {
            $legacyCameras = config('surveillance.cameras', []);
            if (!empty($legacyCameras)) {
                $server = config('surveillance.media_server');
                $deviceConfigs = [[
                    'id'              => 'jetson-default',
                    'name'            => 'Jetson (Default)',
                    'location'        => 'Default',
                    'host'            => $server['host'],
                    'hls_port'        => $server['hls_port'],
                    'webrtc_port'     => $server['webrtc_port'],
                    'hls_base_url'    => $server['hls_base_url'] ?? null,
                    'webrtc_base_url' => $server['webrtc_base_url'] ?? null,
                    'tunnel_cache_key' => TunnelController::CACHE_KEY,
                    'api_token'       => config('surveillance.api_token'),
                    'enabled'         => true,
                    'cameras'         => $legacyCameras,
                ]];
            }
        }

        return collect($deviceConfigs)
            ->filter(fn($d) => $d['enabled'] ?? false)
            ->map(fn($d) => $this->resolveDevice($d))
            ->values();
    }

    /**
     * Resolve a single device: build stream URLs for each camera.
     */
    private function resolveDevice(array $device): array
    {
        $cachedUrl = request()->has('local')
            ? null
            : Cache::get($device['tunnel_cache_key'] ?? '');

        $hlsBase = self::resolveBaseUrl(
            fullUrl: $cachedUrl ?? ($device['hls_base_url'] ?? null),
            host:    $device['host'],
            port:    $device['hls_port'],
        );
        $webrtcBase = self::resolveBaseUrl(
            fullUrl: $cachedUrl ?? ($device['webrtc_base_url'] ?? null),
            host:    $device['host'],
            port:    $device['webrtc_port'],
        );

        $cameras = collect($device['cameras'] ?? [])
            ->filter(fn($cam) => $cam['enabled'] ?? false)
            ->map(function ($cam) use ($hlsBase, $webrtcBase, $device) {
                $pathHd    = $cam['path'];
                $pathSd    = $cam['path_sub']   ?? $cam['path'];
                $pathUltra = $cam['path_ultra'] ?? $cam['path_sub'] ?? $cam['path'];
                $pathLive  = $cam['path_live']  ?? "{$pathHd}_live";

                $settings = Cache::get("camera_settings_{$cam['id']}", [
                    'quality' => 'hd',
                    'fps'     => 15,
                ]);

                return array_merge($cam, [
                    'device_id'       => $device['id'],
                    'hls_url'         => "{$hlsBase}/{$pathLive}/index.m3u8",
                    'webrtc_url'      => "{$webrtcBase}/{$pathHd}",
                    'hls_url_hd'      => "{$hlsBase}/{$pathHd}/index.m3u8",
                    'hls_url_sd'      => "{$hlsBase}/{$pathSd}/index.m3u8",
                    'hls_url_ultra'   => "{$hlsBase}/{$pathUltra}/index.m3u8",
                    'hls_url_live'    => "{$hlsBase}/{$pathLive}/index.m3u8",
                    'current_quality' => $settings['quality'],
                    'current_fps'     => $settings['fps'],
                ]);
            })
            ->values()
            ->toArray();

        // Check device online status
        $isOnline = (bool) Cache::get("jetson_ws_online_{$device['id']}", Cache::get('jetson_ws_online', false));

        return array_merge($device, [
            'cameras'      => $cameras,
            'camera_count' => count($cameras),
            'is_online'    => $isOnline,
            'hls_base'     => $hlsBase,
            'webrtc_base'  => $webrtcBase,
        ]);
    }

    /**
     * Resolve the correct base URL for a MediaMTX endpoint.
     */
    private static function resolveBaseUrl(?string $fullUrl, string $host, int $port): string
    {
        if (!empty($fullUrl)) {
            return rtrim($fullUrl, '/');
        }

        if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
            return rtrim($host, '/');
        }

        return "http://{$host}:{$port}";
    }
}
