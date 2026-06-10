<?php

// app/Services/FacebookService.php

namespace App\Services;

use Facebook\Facebook;
use Facebook\Exceptions\FacebookResponseException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialComment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class FacebookService
{
    private Facebook $fb;
    private SocialAccount $account;

    public function __construct(SocialAccount $account = null)
    {
        $this->fb = new Facebook([
            'app_id' => env('FACEBOOK_APP_ID'),
            'app_secret' => env('FACEBOOK_APP_SECRET'),
            'default_graph_version' => 'v25.0',
        ]);

        if ($account) {
            $this->account = $account;
            $this->fb->setDefaultAccessToken($account->access_token);
        }
    }

    /**
     * Generate OAuth login URL
     */
    public static function getLoginUrl(): string
    {
        $fb = new Facebook([
            'app_id' => env('FACEBOOK_APP_ID'),
            'app_secret' => env('FACEBOOK_APP_SECRET'),
            'default_graph_version' => 'v25.0',
        ]);

        $helper = $fb->getRedirectLoginHelper();
        $permissions = [
            'pages_read_user_content',
            'pages_read_engagement',
            'instagram_basic',
            'pages_show_list',
            'business_management',
            'instagram_manage_comments',
        ];

        return $helper->getLoginUrl(
            env('APP_URL') . '/auth/facebook/callback',
            $permissions
        );
    }

    /**
     * Handle OAuth callback and save access token
     */
    public static function handleCallback(string $accessTokenString, $organizationId, $userId): SocialAccount
    {
        $fb = new Facebook([
            'app_id' => env('FACEBOOK_APP_ID'),
            'app_secret' => env('FACEBOOK_APP_SECRET'),
            'default_graph_version' => 'v25.0',
        ]);

        $fb->setDefaultAccessToken($accessTokenString);

        try {
            // Get user's pages
            $response = $fb->get('/me/accounts?fields=id,name,picture,access_token', $accessTokenString);
            $pageData = $response->getDecodedBody();

            $savedAccounts = [];

            foreach ($pageData['data'] as $page) {
                $account = SocialAccount::create([
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'platform' => 'facebook',
                    'platform_account_id' => $page['id'],
                    'platform_account_name' => $page['name'],
                    'access_token' => $page['access_token'],
                    'profile_picture_url' => $page['picture']['data']['url'] ?? null,
                    'status' => 'connected',
                    'is_active' => true,
                ]);

                $savedAccounts[] = $account;
            }

            return $savedAccounts[0] ?? null;
        } catch (FacebookResponseException $e) {
            Log::error('Facebook OAuth Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get Facebook pages for a user
     */
    public function getPages(): array
    {
        try {
            $response = $this->fb->get('/me/accounts?fields=id,name,picture', $this->account->access_token);
            return $response->getDecodedBody()['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Get Pages Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get connected Instagram accounts
     */
    public function getInstagramAccounts(): array
    {
        try {
            $response = $this->fb->get(
                '/' . $this->account->platform_account_id . '?fields=instagram_business_account',
                $this->account->access_token
            );

            $data = $response->getDecodedBody();
            if (isset($data['instagram_business_account']['id'])) {
                return [$data['instagram_business_account']['id']];
            }
            return [];
        } catch (\Exception $e) {
            Log::error('Get Instagram Accounts Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Sync posts from Facebook page
     */
    public function syncFacebookPosts(): int
    {
        $syncedCount = 0;

        try {
            $response = $this->fb->get(
                '/' . $this->account->platform_account_id . '/posts?fields=id,message,story,permalink_url,type,created_time,likes.summary(true).limit(0),comments.summary(true).limit(0),shares',
                $this->account->access_token
            );

            $postsData = $response->getDecodedBody()['data'] ?? [];

            foreach ($postsData as $post) {
                $existingPost = SocialPost::where(
                    'platform_post_id',
                    $post['id']
                )->first();

                if (!$existingPost) {
                    SocialPost::create([
                        'social_account_id' => $this->account->id,
                        'platform_post_id' => $post['id'],
                        'content' => $post['message'] ?? $post['story'] ?? '',
                        'post_url' => $post['permalink_url'] ?? null,
                        'comments_count' => $post['comments']['summary']['total_count'] ?? 0,
                        'likes_count' => $post['likes']['summary']['total_count'] ?? 0,
                        'shares_count' => $post['shares']['total_count'] ?? 0,
                        'posted_at' => $post['created_time'],
                        'fetched_at' => now(),
                    ]);
                    $syncedCount++;
                }
            }

            return $syncedCount;
        } catch (\Exception $e) {
            Log::error('Sync Facebook Posts Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Sync comments from a Facebook post
     */
    public function syncPostComments(SocialPost $post): int
    {
        $syncedCount = 0;

        try {
            $response = $this->fb->get(
                '/' . $post->platform_post_id . '/comments?fields=id,from,message,created_time,user_likes,comment_count,can_reply_privately',
                $this->account->access_token
            );

            $commentsData = $response->getDecodedBody()['data'] ?? [];

            foreach ($commentsData as $comment) {
                $existingComment = SocialComment::where(
                    'platform_comment_id',
                    $comment['id']
                )->first();

                if (!$existingComment) {
                    SocialComment::create([
                        'social_post_id' => $post->id,
                        'social_account_id' => $this->account->id,
                        'platform_comment_id' => $comment['id'],
                        'platform_author_id' => $comment['from']['id'],
                        'author_name' => $comment['from']['name'],
                        'content' => $comment['message'],
                        'status' => 'new',
                        'commented_at' => $comment['created_time'],
                    ]);
                    $syncedCount++;
                }
            }

            return $syncedCount;
        } catch (\Exception $e) {
            Log::error('Sync Post Comments Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Sync Instagram comments
     */
    public function syncInstagramComments(): int
    {
        $syncedCount = 0;
        $igAccountId = $this->getInstagramAccounts()[0] ?? null;

        if (!$igAccountId) {
            return 0;
        }

        try {
            // Get media (posts and reels)
            $response = $this->fb->get(
                '/' . $igAccountId . '/media?fields=id,caption,timestamp,media_type',
                $this->account->access_token
            );

            $mediaData = $response->getDecodedBody()['data'] ?? [];

            foreach ($mediaData as $media) {
                // Get comments for each media
                $commentsResponse = $this->fb->get(
                    '/' . $media['id'] . '/comments?fields=id,from,text,timestamp',
                    $this->account->access_token
                );

                $commentsData = $commentsResponse->getDecodedBody()['data'] ?? [];

                foreach ($commentsData as $comment) {
                    $existingComment = SocialComment::where(
                        'platform_comment_id',
                        $comment['id']
                    )->first();

                    if (!$existingComment) {
                        // Create or get post
                        $post = SocialPost::firstOrCreate(
                            ['platform_post_id' => $media['id']],
                            [
                                'social_account_id' => $this->account->id,
                                'content' => $media['caption'] ?? '',
                                'posted_at' => $media['timestamp'],
                                'fetched_at' => now(),
                            ]
                        );

                        SocialComment::create([
                            'social_post_id' => $post->id,
                            'social_account_id' => $this->account->id,
                            'platform_comment_id' => $comment['id'],
                            'platform_author_id' => $comment['from']['id'],
                            'author_name' => $comment['from']['username'] ?? $comment['from']['id'],
                            'content' => $comment['text'],
                            'status' => 'new',
                            'commented_at' => $comment['timestamp'],
                        ]);
                        $syncedCount++;
                    }
                }
            }

            return $syncedCount;
        } catch (\Exception $e) {
            Log::error('Sync Instagram Comments Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Publish a reply to a comment
     */
    public function publishReply(SocialComment $comment, string $replyText): bool
    {
        try {
            $response = $this->fb->post(
                '/' . $comment->platform_comment_id . '/private_replies',
                ['message' => $replyText],
                $this->account->access_token
            );

            $responseData = $response->getDecodedBody();
            return isset($responseData['id']);
        } catch (\Exception $e) {
            Log::error('Publish Reply Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Subscribe to webhook for real-time updates
     */
    public function subscribeToWebhook(): bool
    {
        try {
            $response = $this->fb->post(
                '/' . $this->account->platform_account_id . '/subscribed_apps',
                [],
                $this->account->access_token
            );

            return isset($response->getDecodedBody()['success']);
        } catch (\Exception $e) {
            Log::error('Subscribe Webhook Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify webhook signature
     */
    public static function verifyWebhookSignature(string $signature, string $body): bool
    {
        $expectedSignature = hash_hmac(
            'sha256',
            $body,
            env('FACEBOOK_APP_SECRET'),
            true
        );

        return hash_equals(
            bin2hex($expectedSignature),
            $signature
        );
    }

    /**
     * Handle webhook events
     */
    public static function handleWebhookEvent(array $event): void
    {
        if (!isset($event['object']) || $event['object'] !== 'page') {
            return;
        }

        foreach ($event['entry'] ?? [] as $entry) {
            $pageId = $entry['id'];
            $account = SocialAccount::where('platform_account_id', $pageId)->first();

            if (!$account) {
                return;
            }

            // Handle different event types
            foreach ($entry['messaging'] ?? [] as $message) {
                // Handle new messages/comments
                if (isset($message['message'])) {
                    \App\Jobs\ProcessFacebookComment::dispatch($message, $account);
                }
            }

            foreach ($entry['changes'] ?? [] as $change) {
                if ($change['field'] === 'comments') {
                    \App\Jobs\ProcessFacebookComment::dispatch($change['value'], $account);
                }
            }
        }
    }
}