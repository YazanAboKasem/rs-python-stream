<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Services\JetsonWebSocketService;

class DiagnosticController extends Controller
{
    private $wsService;

    public function __construct(JetsonWebSocketService $wsService)
    {
        $this->wsService = $wsService;
    }

    /**
     * POST /api/surveillance/diagnostic/start
     * Body: { "device_id": "rock1" }
     */
    public function start(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $deviceId = $request->input('device_id');
        if (empty($deviceId)) {
            return response()->json(['error' => 'device_id is required'], 422);
        }

        if (! $this->wsService->isOnline($deviceId)) {
            return response()->json([
                'success' => false,
                'error' => 'Device is offline. Cannot run diagnostics.'
            ], 400);
        }

        $requestId = 'diag_' . uniqid();

        // Trigger diagnostic checks on the target device only
        $this->wsService->sendDiagnosticStart($deviceId, $requestId);

        return response()->json([
            'success' => true,
            'request_id' => $requestId,
            'message' => 'Diagnostic checks triggered.'
        ]);
    }

    /**
     * GET /api/surveillance/diagnostic/status/{requestId}?device_id=rock1
     */
    public function status(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $deviceId = $request->query('device_id');

        // Retrieve stashed responses from cache
        $cameraStatus = Cache::get("ws_response_diagnostic.camera_status_{$requestId}");
        $streamStatus = Cache::get("ws_response_diagnostic.stream_status_{$requestId}");
        $tunnelStatus = Cache::get("ws_response_diagnostic.tunnel_status_{$requestId}");
        $logs = Cache::get("ws_response_diagnostic.logs_{$requestId}");

        return response()->json([
            'request_id' => $requestId,
            'jetson_online' => $deviceId ? $this->wsService->isOnline($deviceId) : null,
            'cameras' => $cameraStatus,
            'streams' => $streamStatus,
            'tunnel' => $tunnelStatus,
            'logs' => $logs
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
