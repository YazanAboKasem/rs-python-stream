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

        // Fetch last 10 power logs for this device, formatting started/stopped times in UAE timezone
        $powerLogs = \App\Models\DevicePowerLog::where('device_id', $deviceId)
            ->orderBy('started_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function($log) {
                $started = $log->started_at ? $log->started_at->timezone('Asia/Dubai')->format('Y-m-d H:i:s') : '—';
                $stopped = $log->stopped_at ? $log->stopped_at->timezone('Asia/Dubai')->format('Y-m-d H:i:s') : 'Active Now';
                return [
                    'started_at' => $started,
                    'stopped_at' => $stopped,
                    'reason' => $log->reason ?? '—',
                ];
            });

        return view('surveillance.device-settings', compact('device', 'devices', 'powerLogs'));
    }

    /**
     * Display recordings sync page for a specific device.
     */
    public function syncPage(string $deviceId): View
    {
        $devices = $this->resolveAllDevices();
        $device = $devices->firstWhere('id', $deviceId);

        if (!$device) {
            abort(404, "Device {$deviceId} not found");
        }

        return view('surveillance.sync', compact('device', 'devices'));
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

    /**
     * Display the uploaded recordings browser.
     */
    public function recordings(): View
    {
        $basePath = storage_path('app/recordings');
        $devicesList = [];

        if (\Illuminate\Support\Facades\File::isDirectory($basePath)) {
            foreach (\Illuminate\Support\Facades\File::directories($basePath) as $deviceDir) {
                $deviceName = basename($deviceDir);
                $camerasList = [];

                foreach (\Illuminate\Support\Facades\File::directories($deviceDir) as $camDir) {
                    $camId = basename($camDir);
                    $datesList = [];

                    foreach (\Illuminate\Support\Facades\File::directories($camDir) as $dateDir) {
                        $date = basename($dateDir);
                        $files = [];

                        foreach (\Illuminate\Support\Facades\File::files($dateDir) as $file) {
                            $filename = $file->getFilename();
                            
                            $displayName = $filename;
                            $basename = pathinfo($filename, PATHINFO_FILENAME);
                            $ext = pathinfo($filename, PATHINFO_EXTENSION);
                            $parts = explode('-', $basename);
                            if (count($parts) === 3) {
                                $hour = (int)$parts[0];
                                $min = $parts[1];
                                $amPm = $hour >= 12 ? 'PM' : 'AM';
                                $displayHour = $hour % 12;
                                if ($displayHour === 0) {
                                    $displayHour = 12;
                                }
                                $displayHour = str_pad($displayHour, 2, '0', STR_PAD_LEFT);
                                $displayName = "{$displayHour}:{$min} {$amPm}.{$ext}";
                            }

                            $files[] = [
                                'name' => $filename,
                                'display_name' => $displayName,
                                'size' => round($file->getSize() / (1024 * 1024), 2) . ' MB',
                                'play_url' => route('surveillance.recordings.play', [
                                    'jetsonName' => $deviceName,
                                    'path' => "{$camId}/{$date}/{$filename}"
                                ]),
                            ];
                        }

                        if (!empty($files)) {
                            usort($files, fn($a, $b) => strcmp($b['name'], $a['name']));
                            $datesList[] = [
                                'date' => $date,
                                'files' => $files
                            ];
                        }
                    }

                    if (!empty($datesList)) {
                        usort($datesList, fn($a, $b) => strcmp($b['date'], $a['date']));
                        $camerasList[] = [
                            'camera_id' => $camId,
                            'dates' => $datesList
                        ];
                    }
                }

                if (!empty($camerasList)) {
                    $devicesList[] = [
                        'device_id' => $deviceName,
                        'cameras' => $camerasList
                    ];
                }
            }
        }

        $devices = $this->resolveAllDevices();

        return view('surveillance.recordings', [
            'uploadedDevices' => $devicesList,
            'devices' => $devices
        ]);
    }

    /**
     * Play/stream a specific recording file directly.
     */
    public function playVideo(string $jetsonName, string $path)
    {
        $path = str_replace(['..', "\0"], '', $path);
        $basePath = storage_path('app/recordings');
        $fullPath = "{$basePath}/{$jetsonName}/{$path}";

        if (!\Illuminate\Support\Facades\File::exists($fullPath)) {
            abort(404, "Recording not found");
        }

        return response()->file($fullPath, [
            'Content-Type' => 'video/mp4',
        ]);
    }
}
