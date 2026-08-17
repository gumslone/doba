<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\V1\AriController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\BookingController as ApiBookingController;
use App\Http\Controllers\Api\V1\HotelController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Middleware\ApiRequestId;
use App\Http\Middleware\AuthenticateApiClient;
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

/*
|--------------------------------------------------------------------------
| Partner API v1 (§17)
|--------------------------------------------------------------------------
|
| Versioned in the path, and never a breaking change inside a version.
| Every route is key-pair authenticated and scope-checked; the scope is
| the middleware argument, so a route's permission is declared where the
| route is and cannot drift into a controller.
|
| The controllers are translation layers over AvailabilityService,
| RateResolver and BookingService — the same objects the website's funnel
| uses. That is the rule this whole section rests on: the day the API
| grows its own booking logic is the day it oversells a room the website
| thinks is free.
|
*/
Route::prefix('v1')
    ->middleware([ApiRequestId::class, 'throttle:api'])
    ->group(function (): void {
        Route::middleware(AuthenticateApiClient::class.':hotel:read')->group(function (): void {
            Route::get('hotel', [HotelController::class, 'show']);
            Route::get('room-types', [HotelController::class, 'roomTypes']);
        });

        Route::middleware(AuthenticateApiClient::class.':availability:read')->group(function (): void {
            Route::get('availability', [AvailabilityController::class, 'index']);
            Route::get('search', [AvailabilityController::class, 'search']);
        });

        Route::middleware(AuthenticateApiClient::class.':bookings:read')->group(function (): void {
            Route::get('bookings', [ApiBookingController::class, 'index']);
            Route::get('bookings/{reference}', [ApiBookingController::class, 'show']);
        });

        // ARI push: idempotent range writes, so a retried push changes
        // nothing the first one did not.
        Route::put('availability', [AriController::class, 'availability'])
            ->middleware(AuthenticateApiClient::class.':availability:write');

        Route::put('rates', [AriController::class, 'rates'])
            ->middleware(AuthenticateApiClient::class.':rates:write');

        Route::post('bookings', [ApiBookingController::class, 'store'])
            ->middleware(AuthenticateApiClient::class.':bookings:write');

        Route::post('bookings/{reference}/cancel', [ApiBookingController::class, 'cancel'])
            ->middleware(AuthenticateApiClient::class.':bookings:cancel');

        // A partner manages only its own subscriptions: one key cannot
        // read another's URL or point their events elsewhere.
        Route::middleware(AuthenticateApiClient::class.':bookings:read')->group(function (): void {
            Route::get('webhooks', [WebhookController::class, 'index']);
            Route::post('webhooks', [WebhookController::class, 'store']);
            Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy']);
            Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test']);
        });
    });
