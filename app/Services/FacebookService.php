<?php

// app/Services/FacebookService.php - COMPLETE VERSION

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class FacebookService
{
    private string $graphVersion;

    public function __construct()
    {
        $this->graphVersion = env('FACEBOOK_GRAPH_VERSION', 'v18.0');
    }

    /**
     * Get Facebook login URL for OAuth
     */
    public static function getLoginUrl(): string
    {
        $appId = env('FACEBOOK_APP_ID');
        $redirectUri = env('FACEBOOK_REDIRECT_URI');

        if (!$appId || !$redirectUri) {
            throw new \Exception('Facebook credentials not configured');
        }

        $scope = 'pages_read_user_content,pages_manage_metadata,pages_read_engagement,instagram_basic';
        $state = bin2hex(random_bytes(16));

        session(['facebook_oauth_state' => $state]);

        $service = new self();

        return "https://www.facebook.com/{$service->graphVersion}/dialog/oauth?" .
            "client_id={$appId}" .
            "&redirect_uri=" . urlencode($redirectUri) .
            "&scope={$scope}" .
            "&state={$state}" .
            "&display=popup";
    }

    /**
     * Handle OAuth callback
     */
    public static function handleCallback(string $code, int $organizationId, int $userId): array
    {
        $service = new self();
        return $service->exchangeCodeForAccounts($code, $organizationId, $userId);
    }

    /**
     * Exchange authorization code for accounts
     */
    private function exchangeCodeForAccounts(string $code, int $organizationId, int $userId): array
    {
        $appId = env('FACEBOOK_APP_ID');
        $appSecret = env('FACEBOOK_APP_SECRET');
        $redirectUri = env('FACEBOOK_REDIRECT_URI');

        // Get access token
        $tokenUrl = "https://graph.facebook.com/{$this->graphVersion}/oauth/access_token?" .
            "client_id={$appId}" .
            "&client_secret={$appSecret}" .
            "&redirect_uri=" . urlencode($redirectUri) .
            "&code={$code}";

        $response = Http::get($tokenUrl);
        $tokenResponse = $response->json();

        if (isset($tokenResponse['error'])) {
            throw new \Exception('Facebook error: ' . $tokenResponse['error']['message']);
        }

        $accessToken = $tokenResponse['access_token'];

        // Get user's pages
        $pagesUrl = "https://graph.facebook.com/{$this->graphVersion}/me/accounts?" .
            "fields=id,name,picture,access_token&" .
            "access_token={$accessToken}";

        $response = Http::get($pagesUrl);
        $pagesResponse = $response->json();

        if (isset($pagesResponse['error'])) {
            throw new \Exception('Failed to get pages: ' . $pagesResponse['error']['message']);
        }

        $accounts = [];
        foreach ($pagesResponse['data'] ?? [] as $page) {
            $account = SocialAccount::updateOrCreate(
                [
                    'platform_account_id' => $page['id'],
                    'platform' => 'facebook',
                ],
                [
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'platform_account_name' => $page['name'],
                    'platform_account_handle' => $page['name'],
                    'access_token' => $page['access_token'],
                    'status' => 'connected',
                    'is_active' => true,
                ]
            );
            $accounts[] = $account;
        }

        return $accounts;
    }

    /**
     * Sync all posts and comments from a Facebook page
     */
    public function syncPageComments(SocialAccount $account): int
    {
        try {
            Log::info('Starting sync for account: ' . $account->platform_account_name);

            $totalComments = 0;

            // Get all posts from the page
            $posts = $this->getPagePosts($account);
            Log::info('Found ' . count($posts) . ' posts');

            foreach ($posts as $post) {
                // Store post
                $storedPost = SocialPost::updateOrCreate(
                    [
                        'platform_post_id' => $post['id'],
                        'platform' => 'facebook',
                    ],
                    [
                        'organization_id' => $account->organization_id,
                        'social_account_id' => $account->id,
                        'content' => $post['message'] ?? '',
                        'posted_at' => $post['created_time'] ?? now(),
                    ]
                );

                // Get comments on this post
                $comments = $this->getPostComments($account, $post['id']);
                Log::info('Found ' . count($comments) . ' comments on post ' . $post['id']);

                foreach ($comments as $comment) {
                    $storedComment = SocialComment::updateOrCreate(
                        [
                            'platform_comment_id' => $comment['id'],
                            'platform' => 'facebook',
                        ],
                        [
                            'organization_id' => $account->organization_id,
                            'social_account_id' => $account->id,
                            'social_post_id' => $storedPost->id,
                            'author_name' => $comment['from']['name'] ?? 'Unknown',
                            'platform_author_id' => $comment['from']['id'] ?? 'JinTouchFinancialServices',
                            'content' => $comment['message'] ?? '',
                            'commented_at' => $comment['created_time'] ?? now(),
                            'status' => 'new',
                            'sentiment' => null,
                            'sentiment_score' => null,
                            'intent' => null,
                            'lead_score' => null,
                        ]
                    );

                    $totalComments++;

                    // Dispatch AI analysis job
                    \App\Jobs\AnalyzeCommentWithAI::dispatch($storedComment);
                }
            }

            // Update last sync time
            $account->update(['last_synced_at' => now()]);

            Log::info('Sync completed. Total comments: ' . $totalComments);
            return $totalComments;

        } catch (\Exception $e) {
            Log::error('Sync error for account ' . $account->id . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all posts from a Facebook page
     */

    private function getPagePosts(SocialAccount $account): array
    {
        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$account->platform_account_id}/posts",
            [
                'limit' => 100,
                'access_token' => $account->access_token,
            ]
        );

        Log::info('Facebook Posts Response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception(
                $data['error']['message'] ?? 'Facebook API Error'
            );
        }

        return $data['data'] ?? [];
    }

    /**
     * Get all comments on a post
     */
    private function getPostComments(SocialAccount $account, string $postId): array
    {
        $url = "https://graph.facebook.com/{$this->graphVersion}/{$postId}/comments?" .
            "fields=id,message,created_time,from,type&" .
            "summary=total_count&" .
            "limit=100&" .
            "access_token=" . $account->access_token;

        try {
            $response = Http::get($url);
            $data = $response->json();

            if (isset($data['error'])) {
                Log::error('Error fetching comments for post ' . $postId . ': ' . $data['error']['message']);
                return [];
            }

            return $data['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Exception fetching comments: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Publish a reply to a comment
     */
    public function publishReply(SocialComment $comment, string $message, SocialAccount $account): bool
    {
        try {
            $url = "https://graph.facebook.com/{$this->graphVersion}/" . $comment->platform_comment_id . "/private_replies?" .
                "message=" . urlencode($message) . "&" .
                "access_token=" . $account->access_token;

            $response = Http::post($url);
            $data = $response->json();

            if (isset($data['error'])) {
                Log::error('Error publishing reply: ' . $data['error']['message']);
                return false;
            }

            // Update comment status
            $comment->update([
                'status' => 'replied',
                'ai_response_text' => $message,
                'replied_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Exception publishing reply: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle webhook events from Facebook
     */
    public static function handleWebhookEvent(array $data): void
    {
        Log::info('Facebook webhook event received', $data);

        $entry = $data['entry'] ?? [];

        foreach ($entry as $item) {
            $pageId = $item['id'];
            $changes = $item['changes'] ?? [];

            foreach ($changes as $change) {
                $field = $change['field'];
                $value = $change['value'];

                if ($field === 'feed') {
                    // New post or comment
                    self::handleFeedChange($pageId, $value);
                }
            }
        }
    }

    /**
     * Handle feed changes from webhook
     */
    private static function handleFeedChange(string $pageId, array $data): void
    {
        $account = SocialAccount::where('platform_account_id', $pageId)->first();

        if (!$account) {
            Log::warning('Received webhook for unknown page: ' . $pageId);
            return;
        }

        // If it's a comment
        if (isset($data['comment_id'])) {
            $service = new self();
            $comment = $service->fetchSingleComment($account, $data['comment_id']);

            if ($comment) {
                $storedComment = SocialComment::updateOrCreate(
                    [
                        'platform_comment_id' => $comment['id'],
                        'platform' => 'facebook',
                    ],
                    [
                        'organization_id' => $account->organization_id,
                        'social_account_id' => $account->id,
                        'author_name' => $comment['from']['name'] ?? 'Unknown',
                        'author_id' => $comment['from']['id'] ?? null,
                        'content' => $comment['message'] ?? '',
                        'commented_at' => $comment['created_time'] ?? now(),
                        'status' => 'new',
                    ]
                );

                // Dispatch AI analysis
                \App\Jobs\AnalyzeCommentWithAI::dispatch($storedComment);

                Log::info('New comment stored from webhook: ' . $storedComment->id);
            }
        }
    }

    /**
     * Fetch a single comment by ID
     */
    private function fetchSingleComment(SocialAccount $account, string $commentId): ?array
    {
        $url = "https://graph.facebook.com/{$this->graphVersion}/{$commentId}?" .
            "fields=id,message,created_time,from&" .
            "access_token=" . $account->access_token;

        try {
            $response = Http::get($url);
            $data = $response->json();

            if (isset($data['error'])) {
                Log::error('Error fetching comment: ' . $data['error']['message']);
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('Exception fetching comment: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify webhook signature
     */
    public static function verifyWebhookSignature(string $hubSignature, string $body): bool
    {
        $appSecret = env('FACEBOOK_APP_SECRET');
        $expectedSignature = 'sha1=' . hash_hmac('sha1', $body, $appSecret);

        return hash_equals($expectedSignature, $hubSignature);
    }
}