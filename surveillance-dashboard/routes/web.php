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

// Main surveillance dashboard
Route::get('/surveillance', [SurveillanceController::class, 'index'])
    ->name('surveillance.index');

// Future API routes (Phase 2/3):
// Route::post('/api/surveillance/events', [SurveillanceController::class, 'receiveEvent'])
//     ->name('surveillance.events')
//     ->middleware('throttle:60,1');

