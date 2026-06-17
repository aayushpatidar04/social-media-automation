<?php

// app/Services/FacebookService.php - COMPLETE VERSION

namespace App\Services;

use App\Jobs\AnalyzeCommentWithAI;
use App\Jobs\AnalyzeWithOllama;
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
                        'raw_payload' => $post,
                    ]
                );

                // Get comments on this post
                $comments = $this->getPostComments($account, $post['id']);
                Log::info('Found ' . count($comments) . ' comments on post ' . $post['id']);

                foreach ($comments as $comment) {
                    $storedRootComment = $this->storeFacebookManualComment(
                        account: $account,
                        storedPost: $storedPost,
                        comment: $comment,
                        postId: $post['id'],
                        parentComment: null
                    );

                    if ($storedRootComment?->wasRecentlyCreated) {
                        $totalComments++;

                        if ($this->shouldAnalyzeComment($account, $storedRootComment)) {
                            AnalyzeWithOllama::dispatch($storedRootComment);
                        }
                    }

                    foreach (($comment['comments']['data'] ?? []) as $reply) {
                        $storedReply = $this->storeFacebookManualComment(
                            account: $account,
                            storedPost: $storedPost,
                            comment: $reply,
                            postId: $post['id'],
                            parentComment: $storedRootComment
                        );

                        if ($storedReply?->wasRecentlyCreated) {
                            $totalComments++;

                            if ($this->shouldAnalyzeComment($account, $storedReply)) {
                                AnalyzeWithOllama::dispatch($storedReply);
                            }
                        }
                    }
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

    /**
     * Publish a reply to a comment
     */
    public function publishReply(SocialComment $comment, string $message, SocialAccount $account): bool
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
            return true;
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

        $comment = $value;

        if (!$comment) {
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

        /**
         * Facebook:
         * - If parent_id == post_id, this is root/top-level comment.
         * - If parent_id != post_id, this is reply to another comment.
         */
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
                    ? \Carbon\Carbon::parse($value['created_time'])->setTimezone('Asia/Kolkata')
                    : now()->setTimezone('Asia/Kolkata'),

            ]
        );

        /**
         * If this is a root comment, root_id should point to itself.
         */
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
                'commented_at' => isset($value['created_time'])
                    ? \Carbon\Carbon::parse($value['created_time'])->setTimezone('Asia/Kolkata')
                    : now()->setTimezone('Asia/Kolkata'),


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

            if ($parentComment->root_id) {
                SocialComment::where('id', $parentComment->root_id)->increment('reply_count');
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
}