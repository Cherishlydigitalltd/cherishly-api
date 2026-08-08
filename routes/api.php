<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\GiftRegistryController;
use App\Http\Controllers\MonetaryController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Auth Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('register',        [AuthController::class, 'register']);
    Route::post('verify-email',    [AuthController::class, 'verifyEmail']);
    Route::post('login',           [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('verify-identity', [AuthController::class, 'verifyIdentity']);
    Route::post('resend-otp',      [AuthController::class, 'resendOtp']);
});

/*
|--------------------------------------------------------------------------
| Public Routes (no auth)
|--------------------------------------------------------------------------
*/
Route::prefix('public')->group(function () {
    // Gift Registry
    Route::get('registries/{token}',                          [GiftRegistryController::class, 'publicShow']);
    Route::post('registries/{token}/gifts/{gift}/contribute', [GiftRegistryController::class, 'contribute']);

    // Monetary
    Route::get('monetary/{token}',                [MonetaryController::class, 'publicShow']);
    Route::post('monetary/{token}/contribute',    [MonetaryController::class, 'contribute']);
});

/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('auth/me',              [AuthController::class, 'me']);
    Route::post('auth/logout',         [AuthController::class, 'logout']);

    // Profile
    Route::get('profile',                  [ProfileController::class, 'show']);
    Route::put('profile',                  [ProfileController::class, 'update']);
    Route::post('profile/avatar',          [ProfileController::class, 'updateAvatar']);
    Route::delete('profile/avatar',        [ProfileController::class, 'deleteAvatar']);
    Route::post('profile/change-password', [ProfileController::class, 'changePassword']);

    // Gift Registries
    Route::prefix('registries')->group(function () {
        Route::get('/',              [GiftRegistryController::class, 'index']);
        Route::post('/',             [GiftRegistryController::class, 'store']);
        Route::get('/{registry}',    [GiftRegistryController::class, 'show']);
        Route::put('/{registry}',    [GiftRegistryController::class, 'update']);
        Route::delete('/{registry}', [GiftRegistryController::class, 'destroy']);

        Route::get('/{registry}/gifts',                     [GiftRegistryController::class, 'gifts']);
        Route::post('/{registry}/gifts',                    [GiftRegistryController::class, 'addGift']);
        Route::put('/{registry}/gifts/{gift}',              [GiftRegistryController::class, 'updateGift']);
        Route::delete('/{registry}/gifts/{gift}',           [GiftRegistryController::class, 'deleteGift']);
        Route::get('/{registry}/gifts/{gift}/contributors', [GiftRegistryController::class, 'contributors']);
    });

    // Monetary Gifts
    Route::prefix('monetary')->group(function () {
        Route::get('/',               [MonetaryController::class, 'index']);
        Route::post('/',              [MonetaryController::class, 'store']);
        Route::get('/{monetary}',     [MonetaryController::class, 'show']);
        Route::put('/{monetary}',     [MonetaryController::class, 'update']);
        Route::delete('/{monetary}',  [MonetaryController::class, 'destroy']);
        Route::get('/{monetary}/contributors', [MonetaryController::class, 'contributors']);
    });

});
