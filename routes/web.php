<?php

use App\Http\Controllers\Api\Auth\EmailVerificationController;
use Illuminate\Support\Facades\Route;

// Link from the verification e-mail. Signed, so it works without a session.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->whereNumber('id')
    ->name('verification.verify');

// Named for Laravel's auth redirects; the React router renders the pages.
Route::view('/login', 'app')->name('login');
Route::view('/verify-email', 'app')->name('verification.notice');

// Everything else is handled by the React router.
Route::view('/{path?}', 'app')
    ->where('path', '^(?!api/|build/|storage/|cron/|up$).*$')
    ->name('spa');
