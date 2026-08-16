<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CalendarController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public widget endpoints
|--------------------------------------------------------------------------
|
| Unauthenticated, read-only, rate-limited. The partner API (§17) will live
| under /api/v1 with key-pair authentication and is deliberately NOT here —
| these endpoints serve the site's own JavaScript and nothing else.
|
*/

Route::get('calendar', CalendarController::class)
    ->middleware('throttle:60,1')
    ->name('api.calendar');
