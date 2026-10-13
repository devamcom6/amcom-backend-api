<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BuybackController;
use App\Http\Controllers\Api\V1\CapitalController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EstateController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\HoldingController;
use App\Http\Controllers\Api\V1\ListingController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PropertyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Amcom API v1 — implements docs/BACKEND_API_CONTRACT.md
|--------------------------------------------------------------------------
| Base: /api/v1. Every response uses the { success, message, data } envelope.
*/

Route::prefix('v1')->middleware('throttle:api')->group(function () {

    // ------------------------------------------------------------------ webhooks (Paystack only)
    Route::post('webhooks/paystack', PaystackWebhookController::class)
        ->middleware('throttle:webhooks')
        ->withoutMiddleware('throttle:api');

    // ------------------------------------------------------------------ signed links (no bearer token)
    Route::middleware('signed')->group(function () {
        Route::get('documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
        Route::get('documents/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
        Route::get('inspections/{serviceRequest}/calendar.ics', [DocumentController::class, 'calendar'])->name('inspections.calendar');
    });

    // ------------------------------------------------------------------ auth (public)
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:codes');
        Route::post('password/verify-code', [AuthController::class, 'verifyResetCode'])->middleware('throttle:code-verify');
        Route::post('password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:code-verify');
        Route::get('google/redirect', [GoogleAuthController::class, 'redirect']);
        Route::post('google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:auth');
    });

    // ------------------------------------------------------------------ public catalogue & content
    Route::get('estates', [EstateController::class, 'index']);
    Route::get('estates/{slug}', [EstateController::class, 'show']);
    Route::get('estates/{slug}/plots', [EstateController::class, 'plots']);

    Route::get('capital/plan', [CapitalController::class, 'plan']);

    Route::get('properties', [PropertyController::class, 'index']);
    Route::get('properties/featured', [PropertyController::class, 'featured']);
    Route::get('properties/facets', [PropertyController::class, 'facets']);
    Route::get('properties/{slug}', [PropertyController::class, 'show']);
    Route::get('properties/{slug}/similar', [PropertyController::class, 'similar']);
    Route::get('properties/{slug}/inspection-availability', [PropertyController::class, 'availability']);

    Route::get('content/home', [ContentController::class, 'home']);
    Route::get('content/faqs', [ContentController::class, 'faqs']);
    Route::get('content/pages/{slug}', [ContentController::class, 'page']);
    Route::get('lookups', [ContentController::class, 'lookups']);

    // Public forms (optional auth — linked to the account when a valid token is sent).
    Route::middleware('throttle:public-forms')->group(function () {
        Route::post('buyback/interests', [BuybackController::class, 'interest']);
        Route::post('capital/interests', [CapitalController::class, 'interest']);
        Route::post('properties/{slug}/applications', [PropertyController::class, 'application']);
        Route::post('properties/{slug}/inspections', [PropertyController::class, 'inspection']);
        Route::post('contact', [ContentController::class, 'contact']);
    });

    // ------------------------------------------------------------------ authenticated
    Route::middleware(['auth:sanctum', 'active'])->group(function () {

        // Allowed for unverified emails.
        Route::prefix('auth')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('email/verify', [AuthController::class, 'verifyEmail'])->middleware('throttle:code-verify');
            Route::post('email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:codes');
            Route::post('password/change', [AuthController::class, 'changePassword'])->middleware('throttle:code-verify');
        });

        Route::get('profile', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::post('profile/avatar', [ProfileController::class, 'avatar'])->middleware('throttle:uploads');
        Route::post('profile/identity/verify', [ProfileController::class, 'verifyIdentity'])->middleware('throttle:code-verify');
        Route::put('profile/personal', [ProfileController::class, 'personal']);
        Route::put('profile/contact', [ProfileController::class, 'contact']);
        Route::put('profile/next-of-kin', [ProfileController::class, 'nextOfKin']);

        Route::prefix('account')->group(function () {
            Route::get('notification-preferences', [AccountController::class, 'preferences']);
            Route::put('notification-preferences', [AccountController::class, 'updatePreferences']);
            Route::get('sessions', [AccountController::class, 'sessions']);
            Route::delete('sessions/{id}', [AccountController::class, 'revokeSession'])->whereNumber('id');
            Route::post('phone/change-request', [AccountController::class, 'requestPhoneChange'])->middleware('throttle:codes');
            Route::post('phone/change-confirm', [AccountController::class, 'confirmPhoneChange'])->middleware('throttle:code-verify');
            Route::delete('/', [AccountController::class, 'destroy']);
        });

        Route::post('support/tickets', [AccountController::class, 'supportTicket'])->middleware('throttle:public-forms');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);

        Route::get('documents/{id}/download', [DocumentController::class, 'download'])->whereNumber('id');

        Route::post('media', [MediaController::class, 'store'])->middleware('throttle:uploads');
        Route::patch('media/{id}', [MediaController::class, 'update'])->whereNumber('id');
        Route::delete('media/{id}', [MediaController::class, 'destroy'])->whereNumber('id');

        // Read-only portfolio views.
        Route::prefix('me')->group(function () {
            Route::get('buyback', [PortfolioController::class, 'buyback']);
            Route::get('buyback/positions/{id}', [PortfolioController::class, 'buybackPosition'])->whereNumber('id');
            Route::get('capital', [PortfolioController::class, 'capital']);
            Route::get('capital/positions/{id}', [PortfolioController::class, 'capitalPosition'])->whereNumber('id');
            Route::get('listings', [ListingController::class, 'index']);
            Route::get('listings/{id}', [ListingController::class, 'show'])->whereNumber('id');
            Route::get('holdings', [HoldingController::class, 'index']);
            Route::get('holdings/{id}', [HoldingController::class, 'show'])->whereNumber('id');
            Route::post('holdings/{id}/maintenance-requests', [HoldingController::class, 'maintenance'])->whereNumber('id');
            Route::get('transactions', [PaymentController::class, 'history']);
        });

        Route::get('listings/fee', [ListingController::class, 'fee']);

        // Money-moving and document-issuing actions require a verified email (403 email_unverified).
        Route::middleware('verified.email')->group(function () {
            Route::post('buyback/orders', [BuybackController::class, 'storeOrder']);
            Route::get('buyback/orders/{id}', [BuybackController::class, 'showOrder'])->whereNumber('id');
            Route::get('buyback/orders/{id}/agreement', [BuybackController::class, 'agreement'])->whereNumber('id');
            Route::post('buyback/orders/{id}/agreement/sign', [BuybackController::class, 'sign'])->whereNumber('id');

            Route::post('capital/investments', [CapitalController::class, 'store']);
            Route::get('capital/investments/{id}', [CapitalController::class, 'show'])->whereNumber('id');
            Route::get('capital/investments/{id}/agreement', [CapitalController::class, 'agreement'])->whereNumber('id');
            Route::post('capital/investments/{id}/agreement/sign', [CapitalController::class, 'sign'])->whereNumber('id');

            Route::post('me/capital/positions/{id}/payout-request', [PortfolioController::class, 'payoutRequest'])->whereNumber('id');
            Route::post('me/capital/positions/{id}/reinvest', [PortfolioController::class, 'reinvest'])->whereNumber('id');

            Route::post('listings', [ListingController::class, 'store']);
            Route::put('listings/{id}', [ListingController::class, 'update'])->whereNumber('id');
            Route::post('listings/{id}/photos', [ListingController::class, 'addPhoto'])->whereNumber('id')->middleware('throttle:uploads');
            Route::delete('listings/{id}/photos/{mediaId}', [ListingController::class, 'removePhoto'])->whereNumber(['id', 'mediaId']);
            Route::post('listings/{id}/submit', [ListingController::class, 'submit'])->whereNumber('id');

            Route::post('payments/initialize', [PaymentController::class, 'initialize'])->middleware('throttle:payments');
            Route::get('payments/{reference}', [PaymentController::class, 'show']);
            Route::get('payments/{reference}/verify', [PaymentController::class, 'verify']);

            Route::get('account/payout-accounts', [AccountController::class, 'payoutAccounts']);
            Route::post('account/payout-accounts/resolve', [AccountController::class, 'resolvePayoutAccount'])->middleware('throttle:payments');
            Route::post('account/payout-accounts', [AccountController::class, 'storePayoutAccount'])->middleware('throttle:payments');
            Route::delete('account/payout-accounts/{id}', [AccountController::class, 'destroyPayoutAccount'])->whereNumber('id');

            Route::post('account/two-factor/enable', [AccountController::class, 'enableTwoFactor'])->middleware('throttle:codes');
            Route::post('account/two-factor/confirm', [AccountController::class, 'confirmTwoFactor'])->middleware('throttle:code-verify');
            Route::post('account/two-factor/disable', [AccountController::class, 'disableTwoFactor'])->middleware('throttle:code-verify');
        });

        // ------------------------------------------------------------------ administration
        Route::prefix('admin')->middleware('staff')->group(function () {
            Route::get('dashboard', Admin\DashboardController::class)->middleware('permission:dashboard.view');

            Route::get('users', [Admin\UserController::class, 'index'])->middleware('permission:users.view');
            Route::post('users/bulk', [Admin\UserController::class, 'bulk'])->middleware('permission:users.manage');
            Route::get('users/{id}', [Admin\UserController::class, 'show'])->whereNumber('id')->middleware('permission:users.view');
            Route::patch('users/{id}/status', [Admin\UserController::class, 'updateStatus'])->whereNumber('id')->middleware('permission:users.manage');

            Route::get('estates', [Admin\EstateController::class, 'index'])->middleware('permission:estates.view');
            Route::patch('estates/{id}', [Admin\EstateController::class, 'update'])->whereNumber('id')->middleware('permission:estates.manage');

            Route::get('listings', [Admin\ListingController::class, 'index'])->middleware('permission:listings.view');
            Route::post('listings/{id}/review', [Admin\ListingController::class, 'review'])->whereNumber('id')->middleware('permission:listings.review');

            Route::get('investments', [Admin\InvestmentController::class, 'index'])->middleware('permission:investments.view');
            Route::patch('investments/{id}/status', [Admin\InvestmentController::class, 'updateStatus'])->whereNumber('id')->middleware('permission:investments.manage');

            Route::get('transactions', [Admin\TransactionController::class, 'index'])->middleware('permission:transactions.view');
            Route::post('transactions/{reference}/requery', [Admin\TransactionController::class, 'requery'])->middleware('permission:transactions.manage');

            Route::get('requests', [Admin\RequestController::class, 'index'])->middleware('permission:requests.view');
            Route::patch('requests/{id}', [Admin\RequestController::class, 'update'])->whereNumber('id')->middleware('permission:requests.manage');

            Route::middleware('permission:content.manage')->prefix('content')->group(function () {
                Route::get('faqs', [Admin\ContentController::class, 'faqs']);
                Route::post('faqs', [Admin\ContentController::class, 'storeFaq']);
                Route::put('faqs/{id}', [Admin\ContentController::class, 'updateFaq'])->whereNumber('id');
                Route::delete('faqs/{id}', [Admin\ContentController::class, 'destroyFaq'])->whereNumber('id');
                Route::get('testimonials', [Admin\ContentController::class, 'testimonials']);
                Route::patch('testimonials/{id}', [Admin\ContentController::class, 'updateTestimonial'])->whereNumber('id');
            });

            Route::get('media', Admin\MediaLibraryController::class)->middleware('permission:media.manage');

            Route::get('reports/{type}', [Admin\ReportController::class, 'show'])->middleware('permission:reports.view');
            Route::get('reports/{type}/export', [Admin\ReportController::class, 'export'])->middleware('permission:reports.export');

            Route::get('settings', [Admin\SettingsController::class, 'show'])->middleware('permission:settings.view');
            Route::put('settings', [Admin\SettingsController::class, 'update'])->middleware('permission:settings.manage');

            Route::middleware('permission:admins.manage')->group(function () {
                Route::get('admins', [Admin\AdminAccountController::class, 'index']);
                Route::post('admins', [Admin\AdminAccountController::class, 'store']);
                Route::patch('admins/{id}', [Admin\AdminAccountController::class, 'update'])->whereNumber('id');
                Route::delete('admins/{id}', [Admin\AdminAccountController::class, 'destroy'])->whereNumber('id');
            });

            Route::middleware('permission:roles.manage')->group(function () {
                Route::get('roles', [Admin\RoleController::class, 'index']);
                Route::put('roles/{id}/permissions', [Admin\RoleController::class, 'updatePermissions'])->whereNumber('id');
                Route::get('permissions', [Admin\RoleController::class, 'permissions']);
            });
        });
    });
});
