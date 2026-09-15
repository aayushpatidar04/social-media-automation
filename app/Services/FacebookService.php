<?php

// app/Services/FacebookService.php - COMPLETE VERSION

namespace App\Services;

use App\Jobs\AnalyzeWithOllama;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class FacebookService
{
    private string $graphVersion;

    public function __construct()
    {
        $this->graphVersion = env('FACEBOOK_GRAPH_VERSION', 'v25.0');
    }

    public function syncPageComments(SocialAccount $account, array $options = []): int
    {
        try {
            Log::info('Starting sync for account: ' . $account->platform_account_name);

            $commentWindowDays = $options['comment_window_days'] ?? 7;
            $isFullSync = $options['full_sync'] ?? false;

            $commentCutoff = ($isFullSync || $commentWindowDays === 0)
                ? Carbon::createFromTimestamp(0)
                : now()->subDays($commentWindowDays);

            $totalComments = 0;

            $posts = $this->getPagePosts($account);
            Log::info('Found ' . count($posts) . ' posts');

            foreach ($posts as $post) {
                $publishedAt = $post['created_time'] ?? null;
                $storedPost = SocialPost::updateOrCreate(
                    [
                        'platform_post_id' => $post['id'],
                        'platform' => 'facebook',
                    ],
                    [
                        'organization_id' => $account->organization_id,
                        'social_account_id' => $account->id,
                        'content' => $post['message'] ?? '',
                        'posted_at' => $publishedAt ?? now(),
                        'raw_payload' => $post,
                    ]
                );

                $comments = $this->getPostComments($account, $post['id']);

                foreach ($comments as $comment) {
                    $commentedAt = $comment['created_time'] ?? null;
                    if ($commentedAt && Carbon::parse($commentedAt)->lt($commentCutoff)) {
                        continue;
                    }

                    $storedRootComment = $this->storeFacebookManualComment(
                        account: $account,
                        storedPost: $storedPost,
                        comment: $comment,
                        postId: $post['id'],
                        parentComment: null
                    );

                    if ($storedRootComment?->wasRecentlyCreated) {
                        $totalComments++;

                        if (!$isFullSync && $this->shouldAnalyzeComment($account, $storedRootComment)) {
                            AnalyzeWithOllama::dispatch($storedRootComment);
                        }
                    }

                    foreach (($comment['comments']['data'] ?? []) as $reply) {
                        $replyAt = $reply['created_time'] ?? null;
                        if ($replyAt && Carbon::parse($replyAt)->lt($commentCutoff)) {
                            continue;
                        }

                        $storedReply = $this->storeFacebookManualComment(
                            account: $account,
                            storedPost: $storedPost,
                            comment: $reply,
                            postId: $post['id'],
                            parentComment: $storedRootComment
                        );

                        if ($storedReply?->wasRecentlyCreated) {
                            $totalComments++;

                            if (!$isFullSync && $this->shouldAnalyzeComment($account, $storedReply)) {
                                AnalyzeWithOllama::dispatch($storedReply);
                            }
                        }
                    }
                }
            }

            $account->update(['last_synced_at' => now()]);

            // Auto-sync linked Instagram Business account if this Facebook page has one
            $this->syncLinkedInstagram($account, $options);

            Log::info('Facebook sync completed', [
                'account_id' => $account->id,
                'total_comments' => $totalComments,
            ]);

            return $totalComments;

        } catch (\Exception $e) {
            Log::error('Facebook sync error for account ' . $account->id . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Find and sync the Instagram Business account linked to this Facebook page.
     * Uses the Graph API: /{page-id}?fields=instagram_business_account
     */
    private function syncLinkedInstagram(SocialAccount $facebookAccount, array $options = []): void
    {
        try {
            $igAccountId = $this->getLinkedInstagramAccountId($facebookAccount);

            if (!$igAccountId) {
                Log::info('No linked Instagram account found for Facebook page', [
                    'account_id' => $facebookAccount->id,
                ]);
                return;
            }

            // Find or create the Instagram SocialAccount record
            $igAccount = SocialAccount::where('platform', 'instagram')
                ->where('platform_account_id', $igAccountId)
                ->where('organization_id', $facebookAccount->organization_id)
                ->first();

            if (!$igAccount) {
                $igAccount = SocialAccount::create([
                    'organization_id' => $facebookAccount->organization_id,
                    'user_id' => $facebookAccount->user_id,
                    'platform' => 'instagram',
                    'platform_account_id' => $igAccountId,
                    'platform_account_name' => 'Instagram (' . $igAccountId . ')',
                    'platform_account_handle' => '',
                    'access_token' => $facebookAccount->access_token,
                    'refresh_token' => $facebookAccount->refresh_token,
                    'token_expires_at' => $facebookAccount->token_expires_at,
                    'status' => 'connected',
                    'is_active' => true,
                    'metadata' => [
                        'linked_to_facebook' => $facebookAccount->id,
                        'facebook_page_id' => $facebookAccount->platform_account_id,
                    ],
                ]);

                Log::info('Created Instagram account linked to Facebook page', [
                    'instagram_id' => $igAccount->id,
                    'facebook_id' => $facebookAccount->id,
                    'ig_business_id' => $igAccountId,
                ]);
            }

            // Sync Instagram comments
            $instagramService = new InstagramService();
            $igOptions = array_merge($options, [
                'full_sync' => $options['full_sync'] ?? false,
            ]);

            $igCount = $instagramService->syncComments($igAccount, $igOptions);

            Log::info('Instagram sync completed (via Facebook link)', [
                'instagram_id' => $igAccount->id,
                'new_comments' => $igCount,
                'linked_to_facebook' => $facebookAccount->id,
            ]);

        } catch (\Exception $e) {
            Log::warning('Linked Instagram sync failed', [
                'facebook_id' => $facebookAccount->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Call Graph API to get the linked Instagram Business account ID.
     * Endpoint: GET /{page-id}?fields=instagram_business_account
     */
    public function getLinkedInstagramAccountId(SocialAccount $account): ?string
    {
        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$account->platform_account_id}",
            [
                'fields' => 'instagram_business_account',
                'access_token' => $account->access_token,
            ]
        );

        if (!$response->successful()) {
            Log::warning('Failed to fetch Instagram link for Facebook page', [
                'account_id' => $account->id,
                'status' => $response->status(),
            ]);
            return null;
        }

        $igData = $response->json('instagram_business_account');

        if (!$igData || !isset($igData['id'])) {
            Log::info('No Instagram Business account linked to this Facebook page', [
                'account_id' => $account->id,
            ]);
            return null;
        }

        Log::info('Found linked Instagram Business account', [
            'facebook_id' => $account->id,
            'ig_business_id' => $igData['id'],
        ]);

        return $igData['id'];
    }

    private function getPagePosts(SocialAccount $account): array
    {
        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$account->platform_account_id}/posts",
            [
                'limit' => 100,
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception(
                $data['error']['message'] ?? 'Facebook API Error'
            );
        }

        return $data['data'] ?? [];
    }

    private function getPostComments(SocialAccount $account, string $postId): array
    {
        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$postId}/comments",
            [
                'fields' => 'id,message,created_time,from,parent,comments.limit(100){id,message,created_time,from,parent}',
                'summary' => 'total_count',
                'limit' => 100,
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        if (!$response->successful() || isset($data['error'])) {
            Log::error('Error fetching comments for post ' . $postId, [
                'response' => $data,
            ]);

            return [];
        }

        return $data['data'] ?? [];
    }

    public function publishReply(SocialComment $comment, string $message, SocialAccount $account)
    {
        try {
            $url = "https://graph.facebook.com/{$this->graphVersion}/" . $comment->platform_comment_id . "/comments?" .
                "message=" . urlencode($message) . "&" .
                "access_token=" . $account->access_token;

            $response = Http::post($url);
            $data = $response->json();

            if (isset($data['error'])) {
                Log::error('Error publishing reply: ' . $data['error']['message']);
                return false;
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('Exception publishing reply: ' . $e->getMessage());
            return false;
        }
    }

    public function syncSingleCommentFromWebhook(SocialAccount $account, array $value): ?SocialComment
    {
        $commentId = $value['comment_id'] ?? null;
        $postId = $value['post_id'] ?? null;
        $parentPlatformId = $value['parent_id'] ?? null;

        if (!$commentId || !$postId) {
            return null;
        }

        $storedPost = SocialPost::firstOrCreate(
            [
                'platform_post_id' => $postId,
                'platform' => 'facebook',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'content' => data_get($value, 'post.message', ''),
                'posted_at' => now(),
                'raw_payload' => data_get($value, 'post'),
            ]
        );

        $isTopLevelComment = $parentPlatformId === $postId;
        $parentComment = null;

        if (!$isTopLevelComment && $parentPlatformId) {
            $parentComment = SocialComment::where('platform', 'facebook')
                ->where('platform_comment_id', $parentPlatformId)
                ->first();
        }

        $rootId = null;
        $platformRootId = $commentId;

        if ($parentComment) {
            $rootId = $parentComment->root_id ?: $parentComment->id;
            $platformRootId = $parentComment->platform_root_id ?: $parentComment->platform_comment_id;
        }

        $fromId = data_get($value, 'from.id');
        $fromName = data_get($value, 'from.name', 'Unknown');

        if (!$fromId) {
            Log::warning('Facebook webhook missing author id', [
                'comment_id' => $commentId,
                'value' => $value,
            ]);

            return null;
        }

        $isOwnComment = (string) $fromId === (string) $account->platform_account_id;

        $storedComment = SocialComment::updateOrCreate(
            [
                'platform_comment_id' => $commentId,
                'platform' => 'facebook',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'social_post_id' => $storedPost->id,

                'parent_id' => $parentComment?->id,
                'root_id' => $rootId,

                'platform_parent_id' => $parentPlatformId,
                'platform_root_id' => $platformRootId,

                'author_name' => $fromName,
                'platform_author_id' => $fromId,
                'content' => $value['message'] ?? '',

                'direction' => $isOwnComment ? 'outbound' : 'inbound',
                'sender_type' => $isOwnComment ? 'page' : 'customer',
                'is_own_comment' => $isOwnComment,

                'raw_payload' => $value,
                'commented_at' => isset($value['created_time'])
                    ? \Carbon\Carbon::parse($value['created_time'])->setTimezone(config('app.timezone'))
                    : now()->setTimezone(config('app.timezone')),

            ]
        );

        if (!$storedComment->root_id) {
            $storedComment->update([
                'root_id' => $storedComment->id,
                'platform_root_id' => $storedComment->platform_comment_id,
            ]);
        }

        if ($parentComment) {
            $parentComment->increment('reply_count');

            if ($parentComment->root_id) {
                SocialComment::where('id', $parentComment->root_id)
                    ->increment('reply_count');
            }
        }

        if ($storedComment?->wasRecentlyCreated) {

            if ($this->shouldAnalyzeComment($account, $storedComment)) {
                $storedComment->update(['status' => 'new']);
                AnalyzeWithOllama::dispatch($storedComment);
            }
        }

        return $storedComment;
    }

    private function storeFacebookManualComment(
        SocialAccount $account,
        SocialPost $storedPost,
        array $comment,
        string $postId,
        ?SocialComment $parentComment = null
    ): ?SocialComment {
        $commentId = $comment['id'] ?? null;

        if (!$commentId) {
            return null;
        }

        $fromId = data_get($comment, 'from.id');
        $fromName = data_get($comment, 'from.name', 'Unknown');

        if (!$fromId) {
            Log::warning('Facebook manual sync missing author id', [
                'comment_id' => $commentId,
                'comment' => $comment,
            ]);

            return null;
        }

        $platformParentId = data_get($comment, 'parent.id');

        if (!$platformParentId && $parentComment) {
            $platformParentId = $parentComment->platform_comment_id;
        }

        if (!$platformParentId) {
            $platformParentId = $postId;
        }

        $rootId = null;
        $platformRootId = $commentId;

        if ($parentComment) {
            $rootId = $parentComment->root_id ?: $parentComment->id;
            $platformRootId = $parentComment->platform_root_id ?: $parentComment->platform_comment_id;
        }

        $isOwnComment = (string) $fromId === (string) $account->platform_account_id;

        $storedComment = SocialComment::updateOrCreate(
            [
                'platform_comment_id' => $commentId,
                'platform' => 'facebook',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'social_post_id' => $storedPost->id,

                'parent_id' => $parentComment?->id,
                'root_id' => $rootId,

                'platform_parent_id' => $platformParentId,
                'platform_root_id' => $platformRootId,

                'author_name' => $fromName,
                'platform_author_id' => $fromId,
                'content' => $comment['message'] ?? '',

                'direction' => $isOwnComment ? 'outbound' : 'inbound',
                'sender_type' => $isOwnComment ? 'page' : 'customer',
                'is_own_comment' => $isOwnComment,

                'raw_payload' => $comment,
                'commented_at' => isset($comment['created_time'])
                    ? Carbon::parse($comment['created_time'])->setTimezone(config('app.timezone'))
                    : now()->setTimezone(config('app.timezone')),

                'status' => $isOwnComment ? 'sent' : 'new',
            ]
        );

        if (!$storedComment->root_id) {
            $storedComment->update([
                'root_id' => $storedComment->id,
                'platform_root_id' => $storedComment->platform_comment_id,
            ]);
        }

        if ($parentComment && $storedComment->wasRecentlyCreated) {
            $parentComment->increment('reply_count');

            if ($storedComment->root_id) {
                SocialComment::where('id', $storedComment->root_id)->increment('reply_count');
            }
        }

        return $storedComment;
    }

    private function shouldAnalyzeComment(
        SocialAccount $account,
        SocialComment $comment
    ): bool {
        if (!$comment->wasRecentlyCreated) {
            return false;
        }

        if ($comment->is_own_comment) {
            return false;
        }

        if (!$account->auto_reply_started_at) {
            return false;
        }

        if (!$comment->commented_at) {
            return false;
        }

        return $comment->commented_at->gte(
            $account->auto_reply_started_at
        );
    }

    public function syncSinglePostFromWebhook(SocialAccount $account, array $value): ?SocialPost
    {
        $postId = $value['post_id'] ?? data_get($value, 'post.id');

        if (!$postId) {
            Log::warning('Facebook post webhook missing post_id', [
                'value' => $value,
            ]);

            return null;
        }

        $storedPost = SocialPost::updateOrCreate(
            [
                'platform_post_id' => $postId,
                'platform' => 'facebook',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'content' => data_get($value, 'post.message', ''),
                'posted_at' => now(),
                'raw_payload' => data_get($value, 'post'),
            ]
        );

        Log::info('Facebook webhook post synced', [
            'post_id' => $storedPost->id,
            'platform_post_id' => $postId,
            'item' => $value['item'] ?? null,
        ]);

        return $storedPost;
    }
}
