<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\FacebookAuthController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LinkedInController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TwitterController;
use App\Http\Controllers\YoutubeController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ============================================
// PUBLIC ROUTES (No Auth Required)
// ============================================

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'time' => now()]);
});

// ============================================
// FACEBOOK OAUTH ROUTES (No Auth Required)
// ============================================

Route::get('/auth/facebook', [FacebookAuthController::class, 'login'])
    ->name('auth.facebook');

Route::get('/auth/facebook/callback', [FacebookAuthController::class, 'callback'])
    ->name('auth.facebook.callback');

// ============================================
// AUTHENTICATED ROUTES (Requires Login)
// ============================================

Route::middleware(['auth', 'verified'])->group(function () {

    // ============================================
    // DASHBOARD
    // ============================================

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    // ============================================
    // SOCIAL INBOX & COMMENTS
    // ============================================

    Route::prefix('inbox')->name('comments.')->group(function () {
        Route::get('/', [CommentController::class, 'inbox'])
            ->name('index');

        Route::get('/filter', [CommentController::class, 'filter'])
            ->name('filter');

        Route::get('/{comment}', [CommentController::class, 'show'])
            ->name('show');

        Route::post('/{comment}/reply', [CommentController::class, 'sendReply'])
            ->name('reply');

        Route::post('/{comment}/approve', [CommentController::class, 'approveAIResponse'])
            ->name('approve');

        Route::post('/{comment}/reject', [CommentController::class, 'rejectAIResponse'])
            ->name('reject');

        Route::post('/{comment}/mark-responded', [CommentController::class, 'markAsResponded'])
            ->name('mark-responded');

        Route::post('/{comment}/mark-reviewed', [CommentController::class, 'markAsReviewed'])
            ->name('mark-reviewed');
    });

    // ============================================
    // ANALYTICS
    // ============================================

    Route::prefix('analytics')->name('analytics.')->group(function () {
        Route::get('/', [AnalyticsController::class, 'dashboard'])
            ->name('dashboard');

        Route::get('/metrics/{metricType}', [AnalyticsController::class, 'getMetrics'])
            ->name('metrics');

        Route::post('/export', [AnalyticsController::class, 'exportReport'])
            ->name('export');
    });

    // ============================================
    // LEADS MANAGEMENT
    // ============================================

    Route::prefix('leads')->name('leads.')->group(function () {
        Route::get('/', [LeadController::class, 'index'])
            ->name('index');

        Route::get('/{lead}', [LeadController::class, 'show'])
            ->name('show');

        Route::post('/{lead}/assign', [LeadController::class, 'assign'])
            ->name('assign');

        Route::post('/{lead}/update-status', [LeadController::class, 'updateStatus'])
            ->name('update-status');

        Route::post('/{lead}/contact', [LeadController::class, 'logContact'])
            ->name('log-contact');

        Route::get('/filter', [LeadController::class, 'filter'])
            ->name('filter');
    });

    // ============================================
    // PROFILE SETTINGS
    // ============================================

    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])
            ->name('edit');

        Route::patch('/', [ProfileController::class, 'update'])
            ->name('update');

        Route::delete('/', [ProfileController::class, 'destroy'])
            ->name('destroy');
    });

    // ============================================
    // SETTINGS - SOCIAL ACCOUNTS
    // ============================================

    Route::prefix('settings/social-accounts')->name('settings.social-accounts.')->group(function () {
        Route::get('/', [SocialAccountController::class, 'index'])
            ->name('index');

        Route::post('/{account}/sync', [SocialAccountController::class, 'sync'])
            ->name('sync');

        Route::post('/{account}/disconnect', [SocialAccountController::class, 'disconnect'])
            ->name('disconnect');

        Route::post('/{account}/reconnect', [SocialAccountController::class, 'reconnect'])
            ->name('reconnect');

        Route::post('/{account}/test', [SocialAccountController::class, 'test'])
            ->name('test');
    });

    // ============================================
    // SETTINGS - TEAM
    // ============================================

    Route::prefix('settings/team')->name('settings.team.')->group(function () {
        Route::get('/', function () {
            $organization = Auth::user()->organization;
            return Inertia::render('Settings/Team', [
                'teamMembers' => $organization->users()->get(),
                'currentUserId' => Auth::id(),
            ]);
        })->name('index');

        Route::post('/invite', function () {
            // Invite team member logic here
        })->name('invite');

        Route::delete('/{user}', function () {
            // Remove team member logic here
        })->name('remove');
    });

    // ============================================
    // SETTINGS - ORGANIZATION
    // ============================================

    Route::prefix('settings/organization')->name('settings.organization.')->group(function () {
        Route::get('/', function () {
            $organization = Auth::user()->organization;
            return Inertia::render('Settings/Organization', [
                'organization' => $organization,
            ]);
        })->name('index');

        Route::patch('/', function () {
            // Update organization logic here
        })->name('update');
    });

    // ============================================
    // SETTINGS - API
    // ============================================

    Route::prefix('settings/api')->name('settings.api.')->group(function () {
        Route::get('/', function () {
            return Inertia::render('Settings/API');
        })->name('index');

        Route::post('/keys/generate', function () {
            // Generate API key logic here
        })->name('generate-key');

        Route::delete('/keys/{key}', function () {
            // Revoke API key logic here
        })->name('revoke-key');
    });

    // ============================================
    // SETTINGS - KNOWLEDGE BASE
    // ============================================

    Route::prefix('settings/knowledge-base')->name('settings.knowledge-base.')->group(function () {
        Route::get('/', [KnowledgeBaseController::class, 'index'])
            ->name('index');

        Route::post('/upload', [KnowledgeBaseController::class, 'upload'])
            ->name('upload');

        Route::delete('/{source}', [KnowledgeBaseController::class, 'delete'])
            ->name('delete');
    });

    // ============================================
    // YouTUBE OAUTH & SYNC ROUTES
    // ============================================

    Route::get('/auth/youtube/login', [YoutubeController::class, 'login'])
        ->name('youtube.login');

    Route::get('/auth/youtube/callback', [YoutubeController::class, 'callback'])
        ->name('youtube.callback');

    Route::post('/settings/social-accounts/{account}/youtube-sync', [YoutubeController::class, 'sync'])
        ->name('youtube.sync');

    Route::post('/youtube/comments/{comment}/reply', [YoutubeController::class, 'reply'])
        ->name('youtube.comment.reply');

    // ============================================
    // Twitter OAUTH & SYNC ROUTES
    // ============================================

    Route::get('/auth/twitter/login', [TwitterController::class, 'login'])->name('twitter.login');

    Route::get('/auth/twitter/callback', [TwitterController::class, 'callback'])->name('twitter.callback');

    Route::post('/settings/social-accounts/{account}/twitter-sync', [TwitterController::class, 'sync'])->name('twitter.sync');

    // ============================================
    // LinkedIn OAUTH & SYNC ROUTES
    // ============================================

    Route::get('/auth/linkedin/login', [LinkedInController::class, 'login'])->name('linkedin.login');

    Route::get('/auth/linkedin/callback', [LinkedInController::class, 'callback'])->name('linkedin.callback');

    Route::post('/settings/social-accounts/{account}/linkedin-sync', [LinkedInController::class, 'sync'])->name('linkedin.sync');

    Route::post('/linkedin/comments/{comment}/reply', [LinkedInController::class, 'reply'])->name('linkedin.comment.reply');

});

// ============================================
// WEBHOOK ROUTES (No Auth Required)
// ============================================

Route::post('/webhooks/facebook', function (\Illuminate\Http\Request $request) {
    // Verify webhook signature
    $hubSignature = $request->header('X-Hub-Signature', '');
    $body = $request->getContent();

    if (!$hubSignature || !verifyFacebookSignature($hubSignature, $body)) {
        return response('Unauthorized', 401);
    }

    // Handle webhook event
    \App\Services\FacebookService::handleWebhookEvent($request->all());

    return response()->json(['status' => 'ok']);
})->name('webhooks.facebook');

Route::post('/webhooks/instagram', function (\Illuminate\Http\Request $request) {
    // Verify webhook signature
    $hubSignature = $request->header('X-Hub-Signature', '');
    $body = $request->getContent();

    if (!$hubSignature || !verifyFacebookSignature($hubSignature, $body)) {
        return response('Unauthorized', 401);
    }

    // Handle webhook event
    \App\Services\FacebookService::handleWebhookEvent($request->all());

    return response()->json(['status' => 'ok']);
})->name('webhooks.instagram');

// Webhook verification endpoint (GET)
Route::get('/webhooks/facebook', function (\Illuminate\Http\Request $request) {
    $mode = $request->query('hub_mode');
    $token = $request->query('hub_verify_token');
    $challenge = $request->query('hub_challenge');

    if ($mode === 'subscribe' && $token === env('FACEBOOK_VERIFY_TOKEN')) {
        return $challenge;
    }

    return response('Forbidden', 403);
})->name('webhooks.facebook.verify');

Route::get('/webhooks/instagram', function (\Illuminate\Http\Request $request) {
    $mode = $request->query('hub_mode');
    $token = $request->query('hub_verify_token');
    $challenge = $request->query('hub_challenge');

    if ($mode === 'subscribe' && $token === env('FACEBOOK_VERIFY_TOKEN')) {
        return $challenge;
    }

    return response('Forbidden', 403);
})->name('webhooks.instagram.verify');

// ============================================
// 404 & FALLBACK
// ============================================

Route::fallback(function () {
    return Inertia::render('404');
});

// ============================================
// HELPER FUNCTION
// ============================================

if (!function_exists('verifyFacebookSignature')) {
    function verifyFacebookSignature(string $hubSignature, string $body): bool
    {
        $appSecret = env('FACEBOOK_APP_SECRET');
        $expectedSignature = 'sha1=' . hash_hmac('sha1', $body, $appSecret);

        return hash_equals($expectedSignature, $hubSignature);
    }
}

require __DIR__ . '/auth.php';