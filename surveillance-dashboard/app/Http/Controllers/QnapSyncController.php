<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Services\JetsonWebSocketService;

class QnapSyncController extends Controller
{
    private $wsService;

    public function __construct(JetsonWebSocketService $wsService)
    {
        $this->wsService = $wsService;
    }

    /**
     * POST /api/surveillance/sync/start
     *
     * Tells the Jetson to start uploading recordings to this VPS via HTTP.
     * No QNAP credentials needed — the Jetson uploads directly to Laravel API.
     */
    public function start(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $this->wsService->isOnline()) {
            return response()->json([
                'success' => false,
                'error' => 'Jetson is offline. Cannot start sync.'
            ], 400);
        }

        $request->validate([
            'scope' => 'required|string|in:all,today,last_n_days,cameras',
            'cameras' => 'nullable|array',
            'days' => 'nullable|integer',
            'delete_after_upload' => 'boolean',
            'overwrite_existing' => 'boolean',
            'files' => 'nullable|array',
        ]);

        $requestId = 'sync_' . uniqid();

        // Build VPS upload config — the Jetson will use this to upload files
        // Fall back to the request URL's scheme and host if app.url is localhost or empty
        $baseUrl = rtrim(config('app.url'), '/');
        if (str_contains($baseUrl, 'localhost') || empty($baseUrl)) {
            $baseUrl = $request->getSchemeAndHttpHost();
        }

        $vpsConfig = [
            'upload_url' => $baseUrl . '/api/surveillance/recordings/upload',
            'token' => config('surveillance.api_token'),
        ];

        $options = [
            'scope' => $request->input('scope'),
            'cameras' => $request->input('cameras', []),
            'days' => $request->input('days') ? (int) $request->input('days') : null,
            'delete_after_upload' => $request->boolean('delete_after_upload'),
            'overwrite_existing' => $request->boolean('overwrite_existing'),
            'files' => $request->input('files', []),
        ];

        // Send sync start command via WS
        $this->wsService->sendSyncStart($requestId, $vpsConfig, $options);

        return response()->json([
            'success' => true,
            'request_id' => $requestId,
            'message' => 'Sync recordings command sent to Jetson.',
        ]);
    }

    /**
     * GET /api/surveillance/sync/progress/{requestId}
     */
    public function progress(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check for sync.start.ack, sync.progress, sync.complete
        $startAck = Cache::get("ws_response_sync.start.ack_{$requestId}");
        $progress = Cache::get("ws_response_sync.progress_{$requestId}");
        $complete = Cache::get("ws_response_sync.complete_{$requestId}");

        return response()->json([
            'request_id' => $requestId,
            'start_ack' => $startAck,
            'progress' => $progress,
            'complete' => $complete
        ]);
    }

    /**
     * POST /api/surveillance/sync/pause/{requestId}
     */
    public function pause(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->wsService->sendEvent('sync.pause', ['request_id' => $requestId]);

        return response()->json([
            'success' => true,
            'message' => 'Pause command sent.'
        ]);
    }

    /**
     * POST /api/surveillance/sync/resume/{requestId}
     */
    public function resume(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->wsService->sendEvent('sync.resume', ['request_id' => $requestId]);

        return response()->json([
            'success' => true,
            'message' => 'Resume command sent.'
        ]);
    }

    /**
     * POST /api/surveillance/sync/cancel/{requestId}
     */
    public function cancel(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->wsService->sendEvent('sync.cancel', ['request_id' => $requestId]);

        return response()->json([
            'success' => true,
            'message' => 'Cancel command sent.'
        ]);
    }

    /**
     * POST /api/surveillance/sync/scan
     *
     * Tells the Jetson to list files ready for sync based on filters.
     */
    public function scan(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $this->wsService->isOnline()) {
            return response()->json([
                'success' => false,
                'error' => 'Jetson is offline. Cannot scan files.'
            ], 400);
        }

        $request->validate([
            'scope' => 'required|string|in:all,today,last_n_days,cameras',
            'cameras' => 'nullable|array',
            'days' => 'nullable|integer',
        ]);

        $requestId = 'scan_' . uniqid();
        $options = [
            'scope' => $request->input('scope'),
            'cameras' => $request->input('cameras', []),
            'days' => $request->input('days') ? (int) $request->input('days') : null,
        ];

        $this->wsService->sendSyncListFiles($requestId, $options);

        // Poll cache for response (up to 10.0 seconds)
        $response = $this->wsService->getEventResponse('sync.list_files.ack', $requestId, 10.0);

        if (!$response) {
            return response()->json([
                'success' => false,
                'error' => 'Timeout waiting for Jetson response.'
            ], 408);
        }

        return response()->json([
            'success' => true,
            'files' => $response['files'] ?? [],
        ]);
    }

    /**
     * Helper to validate token
     */
    private function isAuthorized(Request $request): bool
    {
        $token = config('surveillance.api_token');
        if (empty($token)) return false;
        return $request->header('Authorization', '') === "Bearer {$token}";
    }
}
