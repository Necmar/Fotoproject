<?php

use App\Http\Controllers\Api\Account\ProfileController;
use App\Http\Controllers\Api\Admin\BatchController as AdminBatchController;
use App\Http\Controllers\Api\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Api\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Api\Admin\MailController as AdminMailController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Company\BatchController;
use App\Http\Controllers\Api\Company\DownloadController;
use App\Http\Controllers\Api\Company\ImageController;
use App\Http\Controllers\Api\Company\LogoController;
use App\Http\Controllers\Api\Company\SettingsController as CompanySettingsController;
use App\Http\Controllers\Api\MetaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| JSON API for the React app
|--------------------------------------------------------------------------
|
| Loaded with the "web" middleware group and the /api prefix (bootstrap/app.php),
| so it uses cookie sessions + CSRF protection (X-XSRF-TOKEN header).
|
*/

Route::get('meta', MetaController::class)->name('api.meta');

// Guests
Route::middleware('guest')->group(function () {
    Route::post('auth/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('api.login');
    Route::post('auth/register', [RegisterController::class, 'store'])->middleware('throttle:auth')->name('api.register');
    Route::post('auth/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password-reset')->name('api.password.email');
});

// Token-based, so also allowed while signed in (e.g. the Super Admin opening an
// invitation link): it never signs anyone in or out of the current session.
Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset')->name('api.password.store');

// Signed-in users (company owners and Super Admin)
Route::middleware(['auth', 'account.active', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [SessionController::class, 'destroy'])->name('api.logout');
    Route::get('auth/me', [ProfileController::class, 'show'])->name('api.me');
    Route::post('auth/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1,verify-mail')->name('api.verification.send');

    Route::put('account/profile', [ProfileController::class, 'update'])->name('api.profile.update');
    Route::put('account/password', [ProfileController::class, 'updatePassword'])->name('api.password.update');

    // Company owner area. The Super Admin is not a company account.
    Route::middleware(['company', 'verified'])->prefix('company')->name('api.company.')->group(function () {
        Route::get('settings', [CompanySettingsController::class, 'show'])->name('settings.show');
        Route::put('settings', [CompanySettingsController::class, 'update'])->name('settings.update');
        Route::get('stats', [CompanySettingsController::class, 'stats'])->name('stats');

        Route::get('batches', [BatchController::class, 'index'])->name('batches.index');
        Route::post('batches', [BatchController::class, 'store'])->name('batches.store');
        Route::get('batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
        Route::patch('batches/{batch}', [BatchController::class, 'update'])->name('batches.update');
        Route::delete('batches/{batch}', [BatchController::class, 'destroy'])->name('batches.destroy');
        Route::post('batches/{batch}/start', [BatchController::class, 'start'])->name('batches.start');

        Route::post('batches/{batch}/images', [ImageController::class, 'store'])
            ->middleware('throttle:uploads')->name('images.store');
        Route::delete('batches/{batch}/images/{image}', [ImageController::class, 'destroy'])
            ->scopeBindings()->name('images.destroy');
        Route::get('images/{image}/{variant}', [ImageController::class, 'file'])
            ->whereIn('variant', ['original', 'working', 'thumbnail', 'optimized', 'preview'])->name('images.file');
        Route::get('logo', [LogoController::class, 'show'])->name('logo.show');
        Route::post('logo', [LogoController::class, 'store'])->middleware('throttle:10,1,logo')->name('logo.store');
        Route::delete('logo', [LogoController::class, 'destroy'])->name('logo.destroy');

        Route::put('batches/{batch}/watermark', [DownloadController::class, 'watermark'])->name('batches.watermark');
        Route::patch('images/{image}/watermark', [DownloadController::class, 'toggle'])->name('images.watermark');
        Route::get('images/{image}/download', [DownloadController::class, 'image'])->middleware('throttle:120,1,image-download')->name('images.download');
        Route::get('batches/{batch}/download', [DownloadController::class, 'batch'])->middleware('throttle:20,1,zip-download')->name('batches.download');

        Route::post('images/{image}/reoptimize', [ImageController::class, 'reoptimize'])
            ->middleware('throttle:30,1,reoptimize')->name('images.reoptimize');
    });

    // Super Admin area
    Route::middleware('super_admin')->prefix('admin')->name('api.admin.')->group(function () {
        Route::get('dashboard', [AdminSystemController::class, 'dashboard'])->name('dashboard');
        Route::get('mail', [AdminMailController::class, 'show'])->name('mail.show');
        Route::put('mail', [AdminMailController::class, 'update'])->name('mail.update');
        Route::post('mail/test', [AdminMailController::class, 'test'])->middleware('throttle:5,1,mail-test')->name('mail.test');
        Route::post('openai/test', [AdminSystemController::class, 'testOpenAI'])->middleware('throttle:6,1,openai-test')->name('openai.test');
        Route::get('health', [AdminSystemController::class, 'health'])->middleware('throttle:20,1,health')->name('health');
        Route::get('settings', [AdminSystemController::class, 'showSettings'])->name('settings.show');
        Route::put('settings', [AdminSystemController::class, 'updateSettings'])->name('settings.update');
        Route::get('activity', [AdminSystemController::class, 'activity'])->name('activity');

        Route::apiResource('companies', AdminCompanyController::class);
        Route::post('companies/{company}/block', [AdminCompanyController::class, 'block'])->name('companies.block');
        Route::post('companies/{company}/unblock', [AdminCompanyController::class, 'unblock'])->name('companies.unblock');
        Route::post('companies/{company}/password-reset', [AdminCompanyController::class, 'sendPasswordReset'])->middleware('throttle:5,1,admin-reset')->name('companies.password-reset');
        Route::delete('companies/{company}/storage', [AdminCompanyController::class, 'purgeStorage'])->name('companies.storage.destroy');

        Route::get('batches', [AdminBatchController::class, 'index'])->name('batches.index');
        Route::delete('batches/{batch}', [AdminBatchController::class, 'destroy'])->name('batches.destroy');
    });
});

Route::fallback(fn () => response()->json(['message' => __('messages.errors.not_found')], 404));
