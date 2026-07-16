<?php

use App\Http\Controllers\SurveillanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RoadShield Surveillance Dashboard — Web Routes
|--------------------------------------------------------------------------
|
| Phase 1: No authentication required.
| Phase 2: Add auth middleware when user management is needed.
|
*/

// Redirect root to surveillance dashboard
Route::get('/', fn () => redirect()->route('surveillance.index'));

// Main surveillance dashboard (monitoring room — all streams)
Route::get('/surveillance', [SurveillanceController::class, 'index'])
    ->name('surveillance.index');

// Devices management page
Route::get('/surveillance/devices', [SurveillanceController::class, 'devices'])
    ->name('surveillance.devices');

// Device settings page
Route::get('/surveillance/devices/{deviceId}', [SurveillanceController::class, 'deviceSettings'])
    ->name('surveillance.device-settings');

// Uploaded Recordings list & playback
Route::get('/surveillance/recordings', [SurveillanceController::class, 'recordings'])
    ->name('surveillance.recordings');
Route::get('/surveillance/recordings/play/{jetsonName}/{path}', [SurveillanceController::class, 'playVideo'])
    ->where('path', '.*')
    ->name('surveillance.recordings.play');

// Remote Terminal Sessions
Route::post('/surveillance/devices/{deviceId}/terminal/request', [\App\Http\Controllers\DeviceAgentController::class, 'requestTerminal'])
    ->name('surveillance.device-terminal.request');
Route::get('/surveillance/devices/{deviceId}/terminal/status', [\App\Http\Controllers\DeviceAgentController::class, 'terminalStatus'])
    ->name('surveillance.device-terminal.status');

