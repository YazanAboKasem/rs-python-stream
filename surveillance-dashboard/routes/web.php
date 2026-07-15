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
