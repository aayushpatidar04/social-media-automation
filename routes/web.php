<?php

// routes/web.php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacebookAuthController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SocialAccountController;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

// Public routes
Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Social Inbox
    Route::prefix('inbox')->group(function () {
        Route::get('/', [CommentController::class, 'inbox'])->name('inbox');
        Route::get('/filter', [CommentController::class, 'filter'])->name('comments.filter');
        Route::get('/{comment}', [CommentController::class, 'show'])->name('comments.show');
        Route::post('/{comment}/approve-response', [CommentController::class, 'approveAIResponse'])
            ->name('comments.approve-response');
        Route::post('/{comment}/reject-response', [CommentController::class, 'rejectAIResponse'])
            ->name('comments.reject-response');
        Route::post('/{comment}/mark-responded', [CommentController::class, 'markAsResponded'])
            ->name('comments.mark-responded');
    });

    // Analytics
    Route::prefix('analytics')->group(function () {
        Route::get('/', [AnalyticsController::class, 'dashboard'])->name('analytics.dashboard');
        Route::get('/metrics/{metricType}', [AnalyticsController::class, 'getMetrics'])
            ->name('analytics.metrics');
        Route::post('/export', [AnalyticsController::class, 'exportReport'])
            ->name('analytics.export');
    });

    // Leads Management
    Route::prefix('leads')->group(function () {
        Route::get('/', [LeadController::class, 'index'])->name('leads.index');
        Route::get('/{lead}', [LeadController::class, 'show'])->name('leads.show');
        Route::post('/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
        Route::post('/{lead}/update-status', [LeadController::class, 'updateStatus'])
            ->name('leads.update-status');
        Route::post('/{lead}/contact', [LeadController::class, 'logContact'])
            ->name('leads.log-contact');
    });

    // Settings
    Route::prefix('settings')->group(function () {
        // Social Accounts
        Route::prefix('social-accounts')->group(function () {
            Route::get('/', [SocialAccountController::class, 'index'])->name('settings.social-accounts');
            Route::get('/facebook/callback', [SocialAccountController::class, 'handleFacebookCallback'])
                ->name('auth.facebook.callback');
            Route::post('/{account}/disconnect', [SocialAccountController::class, 'disconnect'])
                ->name('social-accounts.disconnect');
            Route::post('/{account}/sync', [SocialAccountController::class, 'syncNow'])
                ->name('social-accounts.sync');
        });

        // Knowledge Base
        Route::prefix('knowledge-base')->group(function () {
            Route::get('/', [KnowledgeBaseController::class, 'index'])
                ->name('settings.knowledge-base');
            Route::post('/upload', [KnowledgeBaseController::class, 'upload'])
                ->name('knowledge-base.upload');
            Route::delete('/{source}', [KnowledgeBaseController::class, 'delete'])
                ->name('knowledge-base.delete');
        });

        // Team Settings
        Route::get('/team', function () {
            return Inertia::render('Settings/Team');
        })->name('settings.team');

        // Organization Settings
        Route::get('/organization', function () {
            return Inertia::render('Settings/Organization');
        })->name('settings.organization');

        // API Settings
        Route::get('/api', function () {
            return Inertia::render('Settings/API');
        })->name('settings.api');
    });

    // Profile
    Route::prefix('profile')->group(function () {
        Route::get('/', function () {
            return Inertia::render('Profile/Edit');
        })->name('profile.edit');
        Route::post('/', [ProfileController::class, 'update'])
            ->name('profile.update');
    });
});

// Webhook routes (should be protected with middleware)
Route::middleware(['webhook.verify'])->group(function () {
    Route::post('/webhooks/facebook', function (Illuminate\Http\Request $request) {
        \App\Services\FacebookService::handleWebhookEvent($request->all());
        return response()->json(['status' => 'ok']);
    })->name('webhooks.facebook');

    Route::post('/webhooks/instagram', function (Illuminate\Http\Request $request) {
        \App\Services\FacebookService::handleWebhookEvent($request->all());
        return response()->json(['status' => 'ok']);
    })->name('webhooks.instagram');
});

// Health check
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// ============================================
// FACEBOOK OAUTH ROUTES
// ============================================

Route::get('/auth/facebook', [FacebookAuthController::class, 'login'])
    ->name('auth.facebook');

Route::get('/auth/facebook/callback', [FacebookAuthController::class, 'callback'])
    ->name('auth.facebook.callback');

Route::middleware('auth')->group(function () {
    Route::post('/facebook/test', [FacebookAuthController::class, 'test'])
        ->name('facebook.test');
});
require __DIR__ . '/auth.php';