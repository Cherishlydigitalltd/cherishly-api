<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\GiftRegistryController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemoryWallController;
use App\Http\Controllers\MonetaryController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecretSantaController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventPageController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Admin\AdminAccountController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminRegistryController;
use App\Http\Controllers\Admin\AdminCatalogController;
use App\Http\Controllers\Admin\AdminMonetaryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\GiftOrderController;
use App\Http\Controllers\Admin\AdminVendorController;
use App\Http\Controllers\Vendor\VendorController;
use App\Http\Controllers\Vendor\VendorOrderController;
use App\Http\Controllers\Vendor\VendorProductController;


/*
|--------------------------------------------------------------------------
| Webhook Routes (no auth — verified by signature + IP)
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks')->group(function () {
    Route::post('payment', [WebhookController::class, 'payment']);
    Route::post('payment/test', [WebhookController::class, 'test']);
});

// Called every minute by an outside cron service (the host has no cron).
Route::get('cron/run', [\App\Http\Controllers\CronController::class, 'run'])
    ->middleware('throttle:10,1');
Route::get('cron/test-alert', [\App\Http\Controllers\CronController::class, 'testAlert'])
    ->middleware('throttle:3,1');

/*
|--------------------------------------------------------------------------
| Public Auth Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:otp');
    Route::post('verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:otp');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp');
    Route::post('verify-identity', [AuthController::class, 'verifyIdentity'])->middleware('throttle:otp');
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:otp');
});

/*
|--------------------------------------------------------------------------
| Public Routes (no auth)
|--------------------------------------------------------------------------
*/
Route::get('payment/verify/{reference}', [PaymentController::class, 'verify']);
Route::post('contact', [App\Http\Controllers\SupportController::class, 'contact'])->middleware('throttle:contact');
Route::get('/public/stats', [PublicController::class, 'stats']);
Route::get('/public/preview/{type}/{token}', [PublicController::class, 'preview'])
    ->middleware('throttle:120,1');

Route::prefix('public')->group(function () {
    // Gift Registries
    Route::get('registries', [GiftRegistryController::class, 'publicIndex']);
    Route::get('registries/{token}', [GiftRegistryController::class, 'publicShow']);
    Route::post('registries/{token}/gifts/{gift}/contribute', [GiftRegistryController::class, 'contribute'])->middleware('throttle:contribution');

    // Monetary
    Route::get('monetary/{token}', [MonetaryController::class, 'publicShow']);
    Route::post('monetary/{token}/contribute', [MonetaryController::class, 'contribute'])->middleware('throttle:contribution');

    // Secret Santa
    Route::get('santa/{token}', [SecretSantaController::class, 'publicShow']);
    Route::post('santa/{token}/reveal', [SecretSantaController::class, 'revealMatch'])
        ->middleware('throttle:10,1');

    // Event pages (cherishlyng.com/e/<slug>)
    Route::get('e/{slug}', [EventPageController::class, 'show']);
    Route::post('e/{slug}/rsvp', [EventPageController::class, 'rsvp'])->middleware('throttle:10,1');

    // Events
    Route::get('events/{token}', [EventController::class, 'publicShow']);
    Route::get('events/{token}/guest/{code}', [EventController::class, 'publicGuest']);
    Route::post('events/{token}/rsvp', [EventController::class, 'publicRsvp']);

    // Invitations
    Route::get('invitations/{token}', [InvitationController::class, 'publicShow']);
    Route::get('invitations/guest/{qrToken}', [InvitationController::class, 'guestByQr']);
    Route::post('invitations/{token}/rsvp/{guestCode}', [InvitationController::class, 'rsvp'])
        ->middleware('throttle:30,1');

    // Digital spray (cherishlyng.com/s/<code>). Limited per guest, not per
    // IP: a whole venue can share one Wi-Fi.
    Route::prefix('spray/{code}')->group(function () {
        Route::get('/', [\App\Http\Controllers\SprayController::class, 'show'])->middleware('throttle:spray-screen');
        Route::get('screen', [\App\Http\Controllers\SprayController::class, 'screen'])->middleware('throttle:spray-screen');
        Route::post('join', [\App\Http\Controllers\SprayController::class, 'join'])->middleware('throttle:spray-join');
        Route::get('me', [\App\Http\Controllers\SprayController::class, 'me'])->middleware('throttle:spray');
        Route::put('me', [\App\Http\Controllers\SprayController::class, 'updateMe'])->middleware('throttle:spray');
        Route::post('topup', [\App\Http\Controllers\SprayController::class, 'topup'])->middleware('throttle:spray-topup');
        Route::post('spray', [\App\Http\Controllers\SprayController::class, 'sprayNotes'])->middleware('throttle:spray');
    });

    // Memory Walls
    Route::get('walls/{token}', [MemoryWallController::class, 'publicShow']);
    Route::get('walls/{token}/wishes', [MemoryWallController::class, 'publicWishes']);
    Route::post('walls/{token}/wishes', [MemoryWallController::class, 'addWish'])
        ->middleware('throttle:10,1');
});

/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'user', 'throttle:api'])->group(function () {

    // Auth
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Profile
    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::delete('profile/avatar', [ProfileController::class, 'deleteAvatar']);
    Route::post('profile/change-password', [ProfileController::class, 'changePassword']);
    Route::get('profile/delete-request', [ProfileController::class, 'deletionRequest']);
    Route::post('profile/delete-request', [ProfileController::class, 'requestDeletion'])->middleware('throttle:5,1');
    Route::delete('profile/delete-request', [ProfileController::class, 'cancelDeletion']);

    // Wallet
    Route::get('wallet', [WalletController::class, 'show']);
    Route::get('wallet/transactions', [WalletController::class, 'transactions']);
    Route::get('wallet/banks', [WalletController::class, 'banks']);
    Route::get('wallet/resolve-account', [WalletController::class, 'resolveAccount']);
    Route::put('wallet/bank-details', [WalletController::class, 'updateBankDetails']);
    Route::post('wallet/withdraw', [WalletController::class, 'withdraw'])->middleware('throttle:withdraw');

    // Gift catalog (auth required)
    Route::get('gifts/catalog', [GiftRegistryController::class, 'catalog']);

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

        // Vendor items: the order, delivery address, cash instead, delivery
        Route::get('/{registry}/gifts/{gift}/order', [GiftOrderController::class, 'show']);
        Route::put('/{registry}/gifts/{gift}/order/address', [GiftOrderController::class, 'address']);
        Route::post('/{registry}/gifts/{gift}/order/received', [GiftOrderController::class, 'received']);
        Route::post('/{registry}/gifts/{gift}/order/problem', [GiftOrderController::class, 'problem']);
        Route::post('/{registry}/gifts/{gift}/take-cash', [GiftOrderController::class, 'takeCash']);
    });

    // Vendor portal
    Route::prefix('vendor')->group(function () {
        Route::get('options', [VendorController::class, 'options']);
        Route::get('me', [VendorController::class, 'me']);
        Route::post('apply', [VendorController::class, 'apply'])->middleware('throttle:10,1');

        Route::middleware('vendor')->group(function () {
            Route::post('profile', [VendorController::class, 'update']);
            Route::put('bank', [VendorController::class, 'bank'])->middleware('throttle:10,1');

            Route::get('products', [VendorProductController::class, 'index']);
            Route::post('products', [VendorProductController::class, 'store']);
            Route::get('products/{product}', [VendorProductController::class, 'show']);
            Route::post('products/{product}', [VendorProductController::class, 'update']);
            Route::put('products/{product}/active', [VendorProductController::class, 'toggle']);
            Route::delete('products/{product}', [VendorProductController::class, 'destroy']);

            Route::get('orders', [VendorOrderController::class, 'index']);
            Route::get('orders/{order}', [VendorOrderController::class, 'show']);
            Route::middleware('vendor:approved')->group(function () {
                Route::post('orders/{order}/accept', [VendorOrderController::class, 'accept']);
                Route::post('orders/{order}/decline', [VendorOrderController::class, 'decline']);
                Route::post('orders/{order}/dispatch', [VendorOrderController::class, 'dispatch']);
                Route::post('orders/{order}/delivered', [VendorOrderController::class, 'delivered']);
            });
        });
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
        Route::post('/{invitation}/checkin-qr', [InvitationController::class, 'checkInByQr']);
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

    // Events
    Route::prefix('events')->group(function () {
        Route::get('/', [EventController::class, 'index']);
        Route::get('/slug-check', [EventPageController::class, 'slugCheck']);
        Route::get('/{event}/page', [EventPageController::class, 'edit']);
        Route::put('/{event}/page', [EventPageController::class, 'update']);
        Route::put('/{event}/page/links', [EventPageController::class, 'links']);
        Route::post('/{event}/page/cover', [EventPageController::class, 'cover']);
        Route::post('/{event}/page/gallery', [EventPageController::class, 'addPhoto']);
        Route::post('/', [EventController::class, 'store']);
        Route::get('/{event}', [EventController::class, 'show']);
        Route::put('/{event}', [EventController::class, 'update']);
        Route::delete('/{event}', [EventController::class, 'destroy']);
        Route::get('/{event}/guests', [EventController::class, 'guests']);
        Route::post('/{event}/guests', [EventController::class, 'addGuests']);
        Route::post('/{event}/guests/import', [EventController::class, 'importGuests']);
        Route::delete('/{event}/guests/{guest}', [EventController::class, 'removeGuest']);
        Route::post('/{event}/guests/{guest}/checkin', [EventController::class, 'checkIn']);
        Route::post('/{event}/checkin-qr', [EventController::class, 'checkInByQr']);
        Route::get('/{event}/attendance', [EventController::class, 'attendance']);
        Route::post('/{event}/send-invitations', [EventController::class, 'sendInvitations']);
        Route::get('/{event}/spray', [\App\Http\Controllers\SprayController::class, 'hostShow']);
        Route::put('/{event}/spray', [\App\Http\Controllers\SprayController::class, 'hostUpdate']);
        Route::post('/{event}/spray/end', [\App\Http\Controllers\SprayController::class, 'hostEnd']);
        Route::put('/{event}/spray/guests/{guest}/message', [\App\Http\Controllers\SprayController::class, 'hostHideMessage']);
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

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/recent', [NotificationController::class, 'recent']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::put('/{id}/read', [NotificationController::class, 'markRead']);
        Route::put('/read-all', [NotificationController::class, 'markAllRead']);
    });

    Route::post('support', [App\Http\Controllers\SupportController::class, 'submit'])
        ->middleware('throttle:5,1');

    Route::get('settings/fees', [SettingsController::class, 'show']);
    Route::put('settings/fees', [SettingsController::class, 'updateFeeBearer']);
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {

    // Auth — no middleware
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('login/verify', [AdminAuthController::class, 'verifyCode'])->middleware('throttle:login');
    Route::post('login/resend', [AdminAuthController::class, 'resendCode'])->middleware('throttle:login');

    // Protected admin routes
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::put('password', [AdminAuthController::class, 'changePassword'])->middleware('throttle:10,1');

        // Admin accounts (super admins only)
        Route::middleware('admin:super_admin')->group(function () {
            Route::get('admins', [AdminAccountController::class, 'index']);
            Route::post('admins', [AdminAccountController::class, 'store']);
            Route::put('admins/{account}/status', [AdminAccountController::class, 'setStatus']);
            Route::put('admins/{account}/password', [AdminAccountController::class, 'resetPassword']);
        });

        // Dashboard
        Route::get('dashboard/stats', [AdminDashboardController::class, 'stats']);
        Route::get('dashboard/activity', [AdminDashboardController::class, 'recentActivity']);

        // Users
        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('users/{user}', [AdminUserController::class, 'show']);
        Route::put('users/{user}/suspend', [AdminUserController::class, 'suspend']);
        Route::put('users/{user}/activate', [AdminUserController::class, 'activate']);
        Route::delete('users/{user}', [AdminUserController::class, 'destroy']);

        // Registries
        Route::get('registries', [AdminRegistryController::class, 'index']);
        Route::get('registries/{registry}', [AdminRegistryController::class, 'show']);
        Route::delete('registries/{registry}', [AdminRegistryController::class, 'destroy']);

        // Catalog gifts
        Route::get('catalog', [AdminCatalogController::class, 'index']);
        Route::post('catalog', [AdminCatalogController::class, 'store']);
        Route::put('catalog/{catalogGift}', [AdminCatalogController::class, 'update']);
        Route::delete('catalog/{catalogGift}', [AdminCatalogController::class, 'destroy']);

        // Vendor marketplace
        Route::get('marketplace/summary', [AdminVendorController::class, 'summary']);
        Route::get('vendors', [AdminVendorController::class, 'vendors']);
        Route::get('vendors/{vendor}', [AdminVendorController::class, 'vendor']);
        Route::put('vendors/{vendor}/status', [AdminVendorController::class, 'setVendorStatus']);
        Route::get('vendor-products', [AdminVendorController::class, 'products']);
        Route::put('vendor-products/{product}/review', [AdminVendorController::class, 'review']);
        Route::get('vendor-orders', [AdminVendorController::class, 'orders']);
        Route::get('vendor-orders/{order}', [AdminVendorController::class, 'order']);
        Route::put('vendor-orders/{order}/resolve', [AdminVendorController::class, 'resolve']);
        Route::post('vendor-orders/{order}/retry-payout', [AdminVendorController::class, 'retryPayout']);
        Route::get('settings/vendor-commission', [AdminVendorController::class, 'commission']);
        Route::put('settings/vendor-commission', [AdminVendorController::class, 'updateCommission']);



        // Account deletion requests
        Route::get('deletion-requests', [\App\Http\Controllers\Admin\AdminDeletionRequestController::class, 'index']);
        Route::put('deletion-requests/{deletionRequest}/complete', [\App\Http\Controllers\Admin\AdminDeletionRequestController::class, 'complete']);
        Route::put('deletion-requests/{deletionRequest}/reject', [\App\Http\Controllers\Admin\AdminDeletionRequestController::class, 'reject']);

        // Customer money (read only)
        Route::get('finance/summary', [\App\Http\Controllers\Admin\AdminFinanceController::class, 'summary']);
        Route::get('wallets', [\App\Http\Controllers\Admin\AdminFinanceController::class, 'wallets']);
        Route::get('wallets/{wallet}', [\App\Http\Controllers\Admin\AdminFinanceController::class, 'wallet']);
        Route::get('transactions', [\App\Http\Controllers\Admin\AdminFinanceController::class, 'transactions']);
        Route::get('transactions/{transaction}', [\App\Http\Controllers\Admin\AdminFinanceController::class, 'transaction']);

        Route::get('monetary', [AdminMonetaryController::class, 'index']);
        Route::get('monetary/{monetaryGift}', [AdminMonetaryController::class, 'show']);
        Route::delete('monetary/{monetaryGift}', [AdminMonetaryController::class, 'destroy']);


        Route::get('spray/topups', [\App\Http\Controllers\Admin\AdminSprayController::class, 'topups']);
        Route::get('settings/spray', [\App\Http\Controllers\Admin\AdminSprayController::class, 'settings']);
        Route::put('settings/spray', [\App\Http\Controllers\Admin\AdminSprayController::class, 'updateSettings']);
        Route::get('settings/fee-rate', [AdminDashboardController::class, 'getGlobalFeeRate']);
        Route::put('settings/fee-rate', [AdminDashboardController::class, 'updateGlobalFeeRate']);
        Route::put('users/{user}/fee-rate', [AdminUserController::class, 'updateFeeRate']);

        // Admin routes
        Route::get('settings/withdrawal-fee', [AdminDashboardController::class, 'getWithdrawalFeeSettings']);
        Route::put('settings/withdrawal-fee', [AdminDashboardController::class, 'updateWithdrawalFeeSettings']);
        Route::put('users/{user}/withdrawal-fee-rate', [AdminUserController::class, 'updateWithdrawalFeeRate']);
    });
});
