<?php

use App\Http\Controllers\ConfigLinkController;
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
