<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\SocialComment;
use App\Services\AnalyticsService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $organization = Auth::user()->organization;

        $analytics = new AnalyticsService($organization);
        $metrics = $analytics->getDashboardMetrics();

        // Fetch recent comments for the dashboard
        $recentComments = SocialComment::whereHas('socialAccount', function ($q) use ($organization) {
            $q->where('organization_id', $organization->id);
        })
            ->with(['socialAccount', 'socialPost'])
            ->latest('commented_at')
            ->limit(5)
            ->get()
            ->map(function ($comment) {
                return [
                    'id' => $comment->id,
                    'author_name' => $comment->author_name,
                    'content' => $comment->content,
                    'commented_at' => $comment->commented_at,
                    'sentiment' => $comment->sentiment,
                    'intent' => $comment->intent,
                    'lead_score' => $comment->lead_score,
                    'social_account' => [
                        'platform' => $comment->socialAccount->platform ?? 'unknown',
                    ],
                ];
            });

        return Inertia::render('Dashboard', [
            'organization' => $organization,
            'metrics' => $metrics,
            'recentComments' => $recentComments,
            'pusher_key' => env('PUSHER_APP_KEY'),
            'pusher_cluster' => env('PUSHER_APP_CLUSTER'),
        ]);
    }
}
