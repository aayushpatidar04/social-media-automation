<?php

// app/Services/AnalyticsService.php

namespace App\Services;

use App\Models\Organization;
use App\Models\SocialComment;
use App\Models\Lead;
use App\Models\AnalyticsCache;
use Pusher\Pusher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AnalyticsService
{
    private Pusher $pusher;
    private Organization $organization;

    public function __construct(Organization $organization)
    {
        $this->organization = $organization;

        $this->pusher = new Pusher(
            env('PUSHER_APP_KEY'),
            env('PUSHER_APP_SECRET'),
            env('PUSHER_APP_ID'),
            [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => true,
            ]
        );
    }

    /**
     * Get all dashboard metrics
     */
    public function getDashboardMetrics(): array
    {
        return [
            'summary' => $this->getSummaryMetrics(),
            'by_platform' => $this->getCommentsByPlatform(),
            'sentiment_distribution' => $this->getSentimentDistribution(),
            'intent_distribution' => $this->getIntentDistribution(),
            'lead_metrics' => $this->getLeadMetrics(),
            'activity_timeline' => $this->getActivityTimeline(),
            'top_authors' => $this->getTopAuthors(),
        ];
    }

    /**
     * Get summary metrics (total, new, responded)
     */
    public function getSummaryMetrics(): array
    {
        $cacheKey = "analytics:summary:{$this->organization->id}";

        return Cache::remember($cacheKey, 300, function () {
            $comments = $this->organization->socialComments();

            return [
                'total_comments' => $comments->count(),
                'new_comments' => $comments->where('social_comments.status', 'new')->count(),
                'responded_comments' => $comments->where('social_comments.status', 'replied')->count(),
                'total_leads' => $this->organization->leads()->count(),
                'new_leads' => $this->organization->leads()->where('lead_status', 'new')->count(),
                'qualified_leads' => $this->organization->leads()->where('lead_status', 'qualified')->count(),
                'response_rate' => $this->getResponseRate(),
                'avg_sentiment_score' => (int) $comments->avg('sentiment_score'),
            ];
        });
    }

    /**
     * Get comments by platform
     */
    public function getCommentsByPlatform(): array
    {
        $cacheKey = "analytics:by_platform:{$this->organization->id}";

        return Cache::remember($cacheKey, 300, function () {
            $platforms = ['facebook', 'instagram', 'youtube', 'twitter', 'linkedin'];
            $data = [];

            foreach ($platforms as $platform) {
                $count = SocialComment::whereHas('socialAccount', function ($q) {
                    $q->where('organization_id', $this->organization->id);
                })
                    ->whereHas('socialPost.socialAccount', function ($q) use ($platform) {
                        $q->where('platform', $platform);
                    })
                    ->count();

                if ($count > 0) {
                    $data[$platform] = [
                        'count' => $count,
                        'percentage' => 0,
                    ];
                }
            }

            // Calculate percentages
            $total = array_sum(array_column($data, 'count'));
            foreach ($data as &$item) {
                $item['percentage'] = $total > 0 ? round(($item['count'] / $total) * 100, 2) : 0;
            }

            return $data;
        });
    }

    /**
     * Get sentiment distribution
     */
    public function getSentimentDistribution(): array
    {
        $cacheKey = "analytics:sentiment:{$this->organization->id}";

        return Cache::remember($cacheKey, 300, function () {
            $comments = $this->organization->socialComments();

            return [
                'positive' => [
                    'count' => $comments->where('sentiment', 'positive')->count(),
                    'percentage' => 0,
                ],
                'neutral' => [
                    'count' => $comments->where('sentiment', 'neutral')->count(),
                    'percentage' => 0,
                ],
                'negative' => [
                    'count' => $comments->where('sentiment', 'negative')->count(),
                    'percentage' => 0,
                ],
                'pending' => [
                    'count' => $comments->where('sentiment', 'pending')->count(),
                    'percentage' => 0,
                ],
            ];
        });
    }

    /**
     * Get intent distribution
     */
    public function getIntentDistribution(): array
    {
        $cacheKey = "analytics:intent:{$this->organization->id}";

        return Cache::remember($cacheKey, 300, function () {
            $comments = $this->organization->socialComments();

            return [
                'sales' => [
                    'count' => $comments->where('intent', 'sales')->count(),
                    'percentage' => 0,
                ],
                'support' => [
                    'count' => $comments->where('intent', 'support')->count(),
                    'percentage' => 0,
                ],
                'complaint' => [
                    'count' => $comments->where('intent', 'complaint')->count(),
                    'percentage' => 0,
                ],
                'question' => [
                    'count' => $comments->where('intent', 'question')->count(),
                    'percentage' => 0,
                ],
                'general' => [
                    'count' => $comments->where('intent', 'general')->count(),
                    'percentage' => 0,
                ],
                'lead' => [
                    'count' => $comments->where('is_lead', true)->count(),
                    'percentage' => 0,
                ],
            ];
        });
    }

    /**
     * Get lead metrics
     */
    public function getLeadMetrics(): array
    {
        $cacheKey = "analytics:leads:{$this->organization->id}";

        return Cache::remember($cacheKey, 300, function () {
            $leads = $this->organization->leads();

            return [
                'total_leads' => $leads->count(),
                'by_status' => [
                    'new' => $leads->where('lead_status', 'new')->count(),
                    'contacted' => $leads->where('lead_status', 'contacted')->count(),
                    'qualified' => $leads->where('lead_status', 'qualified')->count(),
                    'converted' => $leads->where('lead_status', 'converted')->count(),
                    'lost' => $leads->where('lead_status', 'lost')->count(),
                ],
                'by_type' => [
                    'sales' => $leads->where('lead_type', 'sales')->count(),
                    'support' => $leads->where('lead_type', 'support')->count(),
                    'partnership' => $leads->where('lead_type', 'partnership')->count(),
                ],
                'avg_lead_score' => (int) $leads->avg('lead_score'),
                'conversion_rate' => $this->getConversionRate(),
            ];
        });
    }

    /**
     * Get activity timeline (last 24 hours)
     */
    public function getActivityTimeline(): array
    {
        $cacheKey = "analytics:timeline:{$this->organization->id}";

        return Cache::remember($cacheKey, 60, function () {
            $now = Carbon::now();
            $data = [];

            for ($i = 23; $i >= 0; $i--) {
                $hour = $now->copy()->subHours($i);
                $startOfHour = $hour->copy()->startOfHour();
                $endOfHour = $hour->copy()->endOfHour();

                $count = SocialComment::whereHas('socialAccount', function ($q) {
                    $q->where('organization_id', $this->organization->id);
                })
                    ->whereBetween('commented_at', [$startOfHour, $endOfHour])
                    ->count();

                $data[] = [
                    'time' => $hour->format('H:00'),
                    'count' => $count,
                ];
            }

            return $data;
        });
    }

    /**
     * Get top commenting authors
     */
    public function getTopAuthors(int $limit = 10): array
    {
        $cacheKey = "analytics:top_authors:{$this->organization->id}";

        return Cache::remember($cacheKey, 300, function () use ($limit) {
            return SocialComment::select('author_name', 'author_avatar_url', DB::raw('COUNT(*) as comment_count'))
                ->whereHas('socialAccount', function ($q) {
                    $q->where('organization_id', $this->organization->id);
                })
                ->groupBy('author_name', 'author_avatar_url')
                ->orderByDesc('comment_count')
                ->limit($limit)
                ->get()
                ->map(function ($author) {
                    return [
                        'name' => $author->author_name,
                        'avatar' => $author->author_avatar_url,
                        'comment_count' => $author->comment_count,
                    ];
                })
                ->toArray();
        });
    }

    /**
     * Calculate response rate
     */
    private function getResponseRate(): float
    {
        $total = $this->organization->socialComments()->count();
        if ($total === 0) {
            return 0;
        }

        $responded = $this->organization->socialComments()->where('social_comments.status', 'replied')->count();
        return round(($responded / $total) * 100, 2);
    }

    /**
     * Calculate conversion rate
     */
    private function getConversionRate(): float
    {
        $leads = $this->organization->leads()->count();
        if ($leads === 0) {
            return 0;
        }

        $converted = $this->organization->leads()->where('lead_status', 'converted')->count();
        return round(($converted / $leads) * 100, 2);
    }

    /**
     * Broadcast metric update to channel
     */
    public function broadcastMetricUpdate(string $metricType, array $data): bool
    {
        try {
            return $this->pusher->trigger(
                "analytics.org.{$this->organization->id}",
                'metric.updated',
                [
                    'type' => $metricType,
                    'data' => $data,
                    'timestamp' => now()->toIso8601String(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('Pusher Broadcast Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Broadcast comment event
     */
    public function broadcastCommentReceived(SocialComment $comment): bool
    {
        try {
            return $this->pusher->trigger(
                "comments.org.{$this->organization->id}",
                'comment.received',
                [
                    'id' => $comment->id,
                    'author' => $comment->author_name,
                    'content' => substr($comment->content, 0, 100),
                    'platform' => $comment->socialAccount->platform,
                    'timestamp' => $comment->commented_at->toIso8601String(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('Pusher Comment Broadcast Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all analytics cache
     */
    public function clearCache(): void
    {
        $patterns = [
            "analytics:summary:{$this->organization->id}",
            "analytics:by_platform:{$this->organization->id}",
            "analytics:sentiment:{$this->organization->id}",
            "analytics:intent:{$this->organization->id}",
            "analytics:leads:{$this->organization->id}",
            "analytics:timeline:{$this->organization->id}",
            "analytics:top_authors:{$this->organization->id}",
        ];

        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }

    /**
     * Update a specific metric and broadcast
     */
    public function updateMetric(string $metricType): void
    {
        $metrics = [
            'summary' => $this->getSummaryMetrics(),
            'by_platform' => $this->getCommentsByPlatform(),
            'sentiment' => $this->getSentimentDistribution(),
            'intent' => $this->getIntentDistribution(),
            'leads' => $this->getLeadMetrics(),
            'timeline' => $this->getActivityTimeline(),
        ];

        if (isset($metrics[$metricType])) {
            $this->broadcastMetricUpdate($metricType, $metrics[$metricType]);
        }
    }
}