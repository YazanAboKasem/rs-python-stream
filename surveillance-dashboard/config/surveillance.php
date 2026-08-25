<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Token — Tunnel Registration Auth
    |--------------------------------------------------------------------------
    | Used by TunnelController to authenticate requests from connect-to-server.sh
    | Set SURVEILLANCE_TOKEN in .env on both this server and the local machine.
    */
    'api_token' => env('SURVEILLANCE_TOKEN'),

    /*
     * Asset version — bump this to bust browser cache on all devices.
     * Format: MAJOR.MINOR (e.g. 4.0 after any JS/CSS change)
     */
    'asset_version' => '4.8',

    /*
    |--------------------------------------------------------------------------
    | Media Server Defaults (used as fallback when device-level config is missing)
    |--------------------------------------------------------------------------
    */
    'media_server' => [
        'host'         => env('MEDIA_SERVER_HOST', '127.0.0.1'),
        'hls_port'     => env('MEDIA_SERVER_HLS_PORT',    8888),
        'webrtc_port'  => env('MEDIA_SERVER_WEBRTC_PORT', 8889),
        'rtsp_port'    => env('MEDIA_SERVER_RTSP_PORT',   8554),

        'hls_base_url'    => env('MEDIA_SERVER_HLS_URL'),
        'webrtc_base_url' => env('MEDIA_SERVER_WEBRTC_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Devices — LEGACY, now DB-backed
    |--------------------------------------------------------------------------
    |
    | The `devices` array below is no longer read at runtime — see
    | App\Services\DeviceRegistry, which queries the `devices` /
    | `device_cameras` tables instead. New devices no longer need any entry
    | here at all: they self-register (auto-generated id, see
    | rs-python-stream/device_identity.py) and show up under "Discovered
    | Devices" on the dashboard for one-click registration.
    |
    | This array only still exists as the source data for the one-time
    | migration that seeded the `devices` table (see
    | database/migrations/2026_08_16_000003_seed_existing_devices_from_config.php).
    | Safe to delete once that migration has run in every environment.
    |
    | Keys per device:
    |   id              → unique slug (e.g. 'jetson-1')
    |   name            → human-readable name shown in UI
    |   location        → physical location description
    |   host            → MediaMTX host (IP or domain)
    |   hls_port        → MediaMTX HLS port
    |   webrtc_port     → MediaMTX WebRTC port
    |   hls_base_url    → full URL override (Cloudflare Tunnel)
    |   webrtc_base_url → full URL override (Cloudflare Tunnel)
    |   tunnel_cache_key → cache key for dynamic tunnel URL
    |   api_token       → per-device auth token (falls back to global)
    |   enabled         → set false to hide without deleting
    |   cameras         → array of cameras on this device
    |
    */

    'devices' => [
        [
            'id'              => 'rock1',
            'name'            => 'Rock 5B — Main Gate',
            'location'        => 'Front Entrance',
            'host'            => env('JETSON1_HOST', env('MEDIA_SERVER_HOST', '127.0.0.1')),
            'hls_port'        => env('JETSON1_HLS_PORT', env('MEDIA_SERVER_HLS_PORT', 8888)),
            'webrtc_port'     => env('JETSON1_WEBRTC_PORT', env('MEDIA_SERVER_WEBRTC_PORT', 8889)),
            'hls_base_url'    => env('JETSON1_HLS_URL', env('MEDIA_SERVER_HLS_URL')),
            'webrtc_base_url' => env('JETSON1_WEBRTC_URL', env('MEDIA_SERVER_WEBRTC_URL')),
            'tunnel_cache_key' => 'surveillance_tunnel_hls_url_rock1',
            'api_token'       => env('JETSON1_TOKEN', env('SURVEILLANCE_TOKEN')),
            'enabled'         => true,
            'cameras' => [
                [
                    'id'         => 'cam1',
                    'label'      => 'Camera 1 — Front View',
                    'path'       => 'cam1',
                    'path_sub'   => 'cam1_sub',
                    'path_ultra' => 'cam1_ultra',
                    'path_live'  => 'cam1_live',
                    'ip'         => '192.168.1.64',
                    'enabled'    => true,
                    'ptz'        => true,
                ],
                [
                    'id'         => 'cam2',
                    'label'      => 'Camera 2 — Rear PTZ',
                    'path'       => 'cam2',
                    'path_sub'   => 'cam2_sub',
                    'path_ultra' => 'cam2_ultra',
                    'path_live'  => 'cam2_live',
                    'ip'         => '192.168.1.65',
                    'enabled'    => true,
                    'ptz'        => true,
                ],
                [
                    'id'         => 'cam3',
                    'label'      => 'Camera 3 — Back View',
                    'path'       => 'cam3',
                    'path_sub'   => 'cam3_sub',
                    'path_ultra' => 'cam3_ultra',
                    'path_live'  => 'cam3_live',
                    'ip'         => '192.168.1.165',
                    'enabled'    => true,
                    'ptz'        => true,
                ],
            ],
        ],
        [
            'id'              => 'jetson-1',
            'name'            => 'Jetson — Main Gate',
            'location'        => 'Front Entrance',
            'host'            => env('JETSON2_HOST', env('MEDIA_SERVER_HOST', '127.0.0.1')),
            'hls_port'        => env('JETSON2_HLS_PORT', env('MEDIA_SERVER_HLS_PORT', 8888)),
            'webrtc_port'     => env('JETSON2_WEBRTC_PORT', env('MEDIA_SERVER_WEBRTC_PORT', 8889)),
            'hls_base_url'    => env('JETSON2_HLS_URL', env('MEDIA_SERVER_HLS_URL')),
            'webrtc_base_url' => env('JETSON2_WEBRTC_URL', env('MEDIA_SERVER_WEBRTC_URL')),
            'tunnel_cache_key' => 'surveillance_tunnel_hls_url_jetson1',
            'api_token'       => env('JETSON2_TOKEN', env('SURVEILLANCE_TOKEN')),
            'enabled'         => true,
            'cameras' => [
                [
                    'id'         => 'cam1',
                    'label'      => 'Camera 1 — Front View',
                    'path'       => 'cam1',
                    'path_sub'   => 'cam1_sub',
                    'path_ultra' => 'cam1_ultra',
                    'path_live'  => 'cam1_live',
                    'ip'         => '192.168.1.64',
                    'enabled'    => true,
                    'ptz'        => true,
                ],
                [
                    'id'         => 'cam2',
                    'label'      => 'Camera 2 — Rear PTZ',
                    'path'       => 'cam2',
                    'path_sub'   => 'cam2_sub',
                    'path_ultra' => 'cam2_ultra',
                    'path_live'  => 'cam2_live',
                    'ip'         => '192.168.1.65',
                    'enabled'    => true,
                    'ptz'        => true,
                ],
                [
                    'id'         => 'cam3',
                    'label'      => 'Camera 3 — Back View',
                    'path'       => 'cam3',
                    'path_sub'   => 'cam3_sub',
                    'path_ultra' => 'cam3_ultra',
                    'path_live'  => 'cam3_live',
                    'ip'         => '192.168.1.165',
                    'enabled'    => true,
                    'ptz'        => true,
                ],
            ],
        ],

        // ── To add a new Jetson device, copy the block above and update ──
        // See: docs/adding-new-jetson.md for full instructions
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Camera Registry (backward compatibility)
    |--------------------------------------------------------------------------
    | If 'devices' is empty, the system falls back to this flat camera list
    | and treats them as a single default device.
    */
    'cameras' => [
        [
            'id'         => 'cam1',
            'label'      => 'Camera 1 — Front View',
            'path'       => 'cam1',
            'path_sub'   => 'cam1_sub',
            'path_ultra' => 'cam1_ultra',
            'path_live'  => 'cam1_live',
            'ip'         => '192.168.1.64',
            'enabled'    => true,
            'ptz'        => true,
        ],
        [
            'id'         => 'cam2',
            'label'      => 'Camera 2 — Rear PTZ',
            'path'       => 'cam2',
            'path_sub'   => 'cam2_sub',
            'path_ultra' => 'cam2_ultra',
            'path_live'  => 'cam2_live',
            'ip'         => '192.168.1.65',
            'enabled'    => true,
            'ptz'        => true,
        ],
        [
            'id'         => 'cam3',
            'label'      => 'Camera 3 — Back View',
            'path'       => 'cam3',
            'path_sub'   => 'cam3_sub',
            'path_ultra' => 'cam3_ultra',
            'path_live'  => 'cam3_live',
            'ip'         => '192.168.1.165',
            'enabled'    => true,
            'ptz'        => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WebSocket Server Settings
    |--------------------------------------------------------------------------
    */
    'websocket' => [
        'host' => env('WS_HOST', '0.0.0.0'),
        'port' => env('WS_PORT', 6001),
    ],

];
