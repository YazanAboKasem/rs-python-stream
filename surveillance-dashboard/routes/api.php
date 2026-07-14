<?php

use App\Http\Controllers\CameraController;
use App\Http\Controllers\StreamQualityController;
use App\Http\Controllers\TunnelController;
use App\Http\Controllers\DiagnosticController;
use App\Http\Controllers\QnapSyncController;
use App\Http\Controllers\RecordingUploadController;
use App\Http\Controllers\JetsonStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RoadShield Surveillance — API Routes
|--------------------------------------------------------------------------
|
| These routes are called by connect-to-server.sh and camera-control.py.
| Authentication: Bearer token from SURVEILLANCE_TOKEN in .env
|
|*/

// ── Tunnel URL Registration ───────────────────────────────────────────────────
Route::post('/surveillance/register-tunnel',   [TunnelController::class, 'register']);
Route::delete('/surveillance/register-tunnel', [TunnelController::class, 'clear']);
Route::get('/surveillance/tunnel-status',      [TunnelController::class, 'status']);

// ── Jetson Status & WebSocket ──────────────────────────────────────────────────
Route::get('/surveillance/jetson/status', [JetsonStatusController::class, 'status']);
Route::post('/surveillance/jetson/reboot', [JetsonStatusController::class, 'reboot']);

// ── Camera PTZ Control ────────────────────────────────────────────────────────
// Browser sends commands → queued in cache → camera-control.py executes locally
Route::post('/surveillance/cameras/{id}/ptz',       [CameraController::class, 'ptzCommand']);
Route::get('/surveillance/cameras/{id}/ptz/poll',   [CameraController::class, 'ptzPoll']);
Route::post('/surveillance/cameras/{id}/ptz/ack',   [CameraController::class, 'ptzAck']);
Route::get('/surveillance/cameras/{id}/status',     [CameraController::class, 'status']);

// ── Camera Dynamic Settings ───────────────────────────────────────────────────
Route::post('/surveillance/cameras/{id}/settings',   [CameraController::class, 'updateSettings']);
Route::get('/surveillance/cameras/settings',        [CameraController::class, 'getAllSettings']);

// ── Stream Quality Control (Python FFmpeg Transcoding) ────────────────────────
// Browser sets quality preset → cached → camera-control.py polls & restarts FFmpeg
Route::post('/surveillance/cameras/{id}/quality',           [StreamQualityController::class, 'setQuality']);
Route::get('/surveillance/cameras/{id}/quality/settings',   [StreamQualityController::class, 'getSettings']);
Route::get('/surveillance/quality/presets',                  [StreamQualityController::class, 'presets']);

// ── Test Mode & Diagnostics ──────────────────────────────────────────────────
Route::post('/surveillance/diagnostic/start',             [DiagnosticController::class, 'start']);
Route::get('/surveillance/diagnostic/status/{requestId}', [DiagnosticController::class, 'status']);

// ── Recording Sync Control ──────────────────────────────────────────────────
Route::post('/surveillance/sync/start',                [QnapSyncController::class, 'start']);
Route::get('/surveillance/sync/progress/{requestId}',  [QnapSyncController::class, 'progress']);
Route::post('/surveillance/sync/pause/{requestId}',    [QnapSyncController::class, 'pause']);
Route::post('/surveillance/sync/resume/{requestId}',   [QnapSyncController::class, 'resume']);
Route::post('/surveillance/sync/cancel/{requestId}',   [QnapSyncController::class, 'cancel']);

// ── Recording Upload & Browse (VPS Storage) ─────────────────────────────────
Route::post('/surveillance/recordings/upload',                      [RecordingUploadController::class, 'upload']);
Route::get('/surveillance/recordings/browse/{jetsonName?}',         [RecordingUploadController::class, 'browse']);
Route::get('/surveillance/recordings/download/{jetsonName}/{path}', [RecordingUploadController::class, 'download'])
    ->where('path', '.*');
