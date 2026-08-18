<?php

declare(strict_types=1);

use App\Http\Controllers\DirectoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Being findable (§21)
|--------------------------------------------------------------------------
|
| A Doba install is one hotel on its own domain, which is what makes it
| the hotel's own — and also what makes it invisible. These two endpoints
| let an aggregator find it and price it, and neither takes the booking:
| every quote links back into this hotel's own funnel.
|
| Registered outside the `web` group on purpose. Both answers are public,
| identical for every caller and marked cacheable, and a session cookie
| on a `Cache-Control: public` response is a contradiction no CDN will
| store — so an aggregator polling a few thousand hotels would miss every
| cache it should have hit.
|
| Never localised, either. An aggregator is not a reader, and a
| well-known URI that moves per language is not well known.
|
*/

Route::middleware('api')->group(function (): void {
    Route::get('.well-known/doba.json', [DirectoryController::class, 'wellKnown'])
        ->middleware('throttle:60,1')
        ->name('directory.well-known');

    Route::get('api/directory/quote', [DirectoryController::class, 'quote'])
        ->middleware('throttle:120,1')
        ->name('directory.quote');
});
