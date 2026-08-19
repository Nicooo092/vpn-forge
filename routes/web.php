<?php

use App\Http\Controllers\ConfigLinkController;
use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// One-time config links. Public and unauthenticated -- the token is the
// credential -- so both are rate limited against token guessing, and the
// token is constrained to the alphanumerics Str::random produces so nothing
// odd reaches the lookup. GET only peeks; POST reveals and spends a use.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/c/{token}', [ConfigLinkController::class, 'show'])
        ->name('config-link.show')
        ->whereAlphaNumeric('token');

    Route::post('/c/{token}', [ConfigLinkController::class, 'reveal'])
        ->name('config-link.reveal')
        ->whereAlphaNumeric('token');
});

// Prometheus metrics for a monitoring scraper. Outside the Filament panel and
// its session auth -- a scraper carries no cookie -- but MetricsController
// requires a bearer token (constant-time compare) and 404s when none is
// configured, so nothing is disclosed unless deliberately enabled. Rate limited
// against token guessing, like the config-link routes.
Route::middleware('throttle:30,1')
    ->get('/metrics', MetricsController::class)
    ->name('metrics');
