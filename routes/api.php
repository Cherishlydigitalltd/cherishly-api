<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\GiftRegistryController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemoryWallController;
use App\Http\Controllers\MonetaryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecretSantaController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes (no auth — verified by signature)
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks')->group(function () {
    Route::post('payment', [WebhookController::class, 'payment']);
    Route::post('payment/test', [WebhookController::class, 'test']);
});

/*
|--------------------------------------------------------------------------
| Public Auth Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('verify-identity', [AuthController::class, 'verifyIdentity']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('resend-otp', [AuthController::class, 'resendOtp']);
});

/*
|--------------------------------------------------------------------------
| Public Routes (no auth)
|--------------------------------------------------------------------------
*/
Route::prefix('public')->group(function () {
    Route::get('registries/{token}', [GiftRegistryController::class, 'publicShow']);
    Route::post('registries/{token}/gifts/{gift}/contribute', [GiftRegistryController::class, 'contribute']);
    Route::get('monetary/{token}', [MonetaryController::class, 'publicShow']);
    Route::post('monetary/{token}/contribute', [MonetaryController::class, 'contribute']);
    Route::get('invitations/{token}', [InvitationController::class, 'publicShow']);
    Route::get('invitations/guest/{qrToken}', [InvitationController::class, 'guestByQr']);
    Route::post('invitations/{token}/rsvp/{guest}', [InvitationController::class, 'rsvp']);
    Route::get('walls/{token}', [MemoryWallController::class, 'publicShow']);
    Route::get('walls/{token}/wishes', [MemoryWallController::class, 'publicWishes']);
    Route::post('walls/{token}/wishes', [MemoryWallController::class, 'addWish']);
});

/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Profile
    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::delete('profile/avatar', [ProfileController::class, 'deleteAvatar']);
    Route::post('profile/change-password', [ProfileController::class, 'changePassword']);

    // Wallet
    Route::get('wallet', [WalletController::class, 'show']);
    Route::get('wallet/transactions', [WalletController::class, 'transactions']);
    Route::put('wallet/bank-details', [WalletController::class, 'updateBankDetails']);
    Route::post('wallet/withdraw', [WalletController::class, 'withdraw']);

    // Gift Registries
    Route::prefix('registries')->group(function () {
        Route::get('/', [GiftRegistryController::class, 'index']);
        Route::post('/', [GiftRegistryController::class, 'store']);
        Route::get('/{registry}', [GiftRegistryController::class, 'show']);
        Route::put('/{registry}', [GiftRegistryController::class, 'update']);
        Route::delete('/{registry}', [GiftRegistryController::class, 'destroy']);
        Route::get('/{registry}/gifts', [GiftRegistryController::class, 'gifts']);
        Route::post('/{registry}/gifts', [GiftRegistryController::class, 'addGift']);
        Route::put('/{registry}/gifts/{gift}', [GiftRegistryController::class, 'updateGift']);
        Route::delete('/{registry}/gifts/{gift}', [GiftRegistryController::class, 'deleteGift']);
        Route::get('/{registry}/gifts/{gift}/contributors', [GiftRegistryController::class, 'contributors']);
    });

    // Monetary Gifts
    Route::prefix('monetary')->group(function () {
        Route::get('/', [MonetaryController::class, 'index']);
        Route::post('/', [MonetaryController::class, 'store']);
        Route::get('/{monetary}', [MonetaryController::class, 'show']);
        Route::put('/{monetary}', [MonetaryController::class, 'update']);
        Route::delete('/{monetary}', [MonetaryController::class, 'destroy']);
        Route::get('/{monetary}/contributors', [MonetaryController::class, 'contributors']);
    });

    // Invitations
    Route::prefix('invitations')->group(function () {
        Route::get('/', [InvitationController::class, 'index']);
        Route::post('/', [InvitationController::class, 'store']);
        Route::get('/{invitation}', [InvitationController::class, 'show']);
        Route::put('/{invitation}', [InvitationController::class, 'update']);
        Route::delete('/{invitation}', [InvitationController::class, 'destroy']);
        Route::get('/{invitation}/guests', [InvitationController::class, 'guests']);
        Route::post('/{invitation}/guests', [InvitationController::class, 'addGuests']);
        Route::post('/{invitation}/guests/import', [InvitationController::class, 'importGuests']);
        Route::delete('/{invitation}/guests/{guest}', [InvitationController::class, 'removeGuest']);
        Route::post('/{invitation}/guests/{guest}/checkin', [InvitationController::class, 'checkIn']);
        Route::post('/{invitation}/send', [InvitationController::class, 'send']);
        Route::get('/{invitation}/attendance', [InvitationController::class, 'attendance']);
    });

    // Secret Santa
    Route::prefix('santa')->group(function () {
        Route::get('/', [SecretSantaController::class, 'index']);
        Route::post('/', [SecretSantaController::class, 'store']);
        Route::get('/{santa}', [SecretSantaController::class, 'show']);
        Route::put('/{santa}', [SecretSantaController::class, 'update']);
        Route::delete('/{santa}', [SecretSantaController::class, 'destroy']);
        Route::get('/{santa}/participants', [SecretSantaController::class, 'participants']);
        Route::post('/{santa}/participants', [SecretSantaController::class, 'addParticipants']);
        Route::post('/{santa}/participants/import', [SecretSantaController::class, 'importParticipants']);
        Route::delete('/{santa}/participants/{participant}', [SecretSantaController::class, 'removeParticipant']);
        Route::post('/{santa}/generate-matches', [SecretSantaController::class, 'generateMatches']);
    });

    // Memory Walls
    Route::prefix('walls')->group(function () {
        Route::get('/', [MemoryWallController::class, 'index']);
        Route::post('/', [MemoryWallController::class, 'store']);
        Route::get('/{wall}', [MemoryWallController::class, 'show']);
        Route::put('/{wall}', [MemoryWallController::class, 'update']);
        Route::delete('/{wall}', [MemoryWallController::class, 'destroy']);
        Route::get('/{wall}/wishes', [MemoryWallController::class, 'wishes']);
        Route::delete('/{wall}/wishes/{wish}', [MemoryWallController::class, 'deleteWish']);
    });

});
