<?php

use App\Http\Controllers\AuthPreparationController;
use App\Http\Controllers\CaregiverLinkController;
use App\Http\Controllers\OtpAuthenticationController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Resources\UserResource;
use App\Support\AuthApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Get authenticated user
Route::get('/user', function (Request $request) {
    return AuthApiResponse::success('user-retrieved', ['user' => (new UserResource($request->user()))->resolve($request)]);
})->middleware('auth:sanctum');

Route::prefix('auth')->group(function (): void {
    Route::post('/password/eligibility', [PasswordRecoveryController::class, 'eligibility'])->middleware('throttle:auth-recovery');
    Route::post('/password/forgot', [PasswordRecoveryController::class, 'forgot'])->middleware('throttle:auth-recovery');
    Route::post('/password/otp/send', [PasswordRecoveryController::class, 'send'])->middleware('throttle:auth-otp-send');
    Route::post('/password/otp/verify', [PasswordRecoveryController::class, 'verify'])->middleware('throttle:auth-otp-verify');
    Route::post('/password/reset', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:auth-finalize');
    Route::post('/availability/email', [AuthPreparationController::class, 'emailAvailability'])->middleware('throttle:auth-availability');
    Route::post('/availability/username', [AuthPreparationController::class, 'usernameAvailability'])->middleware('throttle:auth-availability');
    Route::post('/login', [AuthPreparationController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/register/prepare', [AuthPreparationController::class, 'register'])->middleware('throttle:auth-registration');
    Route::post('/signup/start', [OtpAuthenticationController::class, 'signup'])->middleware('throttle:auth-registration');
    Route::post('/login/start', [OtpAuthenticationController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/otp/send', [OtpAuthenticationController::class, 'send'])->middleware('throttle:auth-otp-send');
    Route::post('/otp/verify', [OtpAuthenticationController::class, 'verify'])->middleware('throttle:auth-otp-verify');
    Route::post('/register', [OtpAuthenticationController::class, 'finalize'])->middleware('throttle:auth-finalize');
    Route::post('/logout', [OtpAuthenticationController::class, 'logout'])->middleware('auth:sanctum');
});

// SANAD API Health Check
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/caregivers/lookup', [CaregiverLinkController::class, 'lookup'])->name('caregiver-links.lookup')->middleware('throttle:caregiver-lookup');
    Route::get('/caregiver-links', [CaregiverLinkController::class, 'index'])->name('caregiver-links.index')->middleware('throttle:caregiver-read');
    Route::post('/caregiver-links', [CaregiverLinkController::class, 'store'])->name('caregiver-links.store')->middleware('throttle:caregiver-write');
    Route::post('/caregiver-links/{id}/{action}', [CaregiverLinkController::class, 'transition'])
        ->whereIn('action', ['accept', 'decline', 'cancel', 'revoke'])->name('caregiver-links.transition')->middleware('throttle:caregiver-write');
});

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'SANAD API is running',
        'environment' => app()->environment(),
    ]);
});
