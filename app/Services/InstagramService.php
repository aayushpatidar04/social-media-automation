<?php

// app/Services/InstagramService.php

namespace App\Services;

use App\Jobs\AnalyzeWithOllama;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class InstagramService
{
    protected string $graphVersion = 'v25.0';

    public function syncComments(SocialAccount $account, array $options = []): int
    {
        $postWindowDays = $options['post_window_days'] ?? 30;
        $commentWindowDays = $options['comment_window_days'] ?? 7;
        $postCutoff = now()->subDays($postWindowDays);
        $commentCutoff = now()->subDays($commentWindowDays);

        $totalComments = 0;
        $skippedOldPosts = 0;

        $mediaList = $this->getMedia($account);

        foreach ($mediaList as $media) {
            // Skip media older than the post window
            $publishedAt = $media['timestamp'] ?? null;
            if ($publishedAt && Carbon::parse($publishedAt)->lt($postCutoff)) {
                $skippedOldPosts++;
                continue;
            }

            $storedPost = SocialPost::updateOrCreate(
                [
                    'platform_post_id' => $media['id'],
                    'platform' => 'instagram',
                ],
                [
                    'organization_id' => $account->organization_id,
                    'social_account_id' => $account->id,
                    'content' => $media['caption'] ?? '',
                    'posted_at' => $publishedAt ?? now(),
                    'raw_payload' => $media,
                ]
            );

            $comments = $this->getMediaComments(
                $account,
                $media['id']
            );

            foreach ($comments as $comment) {
                // Skip comments older than the window
                $commentedAt = $comment['timestamp'] ?? null;
                if ($commentedAt && Carbon::parse($commentedAt)->lt($commentCutoff)) {
                    continue;
                }

                $storedRootComment = $this->storeInstagramManualComment(
                    account: $account,
                    storedPost: $storedPost,
                    comment: $comment,
                    parentComment: null
                );

                if ($storedRootComment?->wasRecentlyCreated) {
                    $totalComments++;

                    if ($this->shouldAnalyzeComment($account, $storedRootComment)) {
                        AnalyzeWithOllama::dispatch($storedRootComment);
                    }
                }

                foreach (($comment['replies']['data'] ?? []) as $reply) {
                    // Skip replies older than the window
                    $replyAt = $reply['timestamp'] ?? null;
                    if ($replyAt && Carbon::parse($replyAt)->lt($commentCutoff)) {
                        continue;
                    }

                    $storedReply = $this->storeInstagramManualComment(
                        account: $account,
                        storedPost: $storedPost,
                        comment: $reply,
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

        $account->update(['last_synced_at' => now()]);

        Log::info('Instagram sync completed', [
            'account_id' => $account->id,
            'total_comments' => $totalComments,
            'skipped_old_posts' => $skippedOldPosts,
        ]);

        return $totalComments;
    }

    private function getConnectedInstagramAccount(
        SocialAccount $account
    ): ?string {
        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$account->platform_account_id}",
            [
                'fields' => 'connected_instagram_account',
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        Log::info('Instagram Account Lookup', $data);

        if (!$response->successful()) {
            throw new \Exception(
                $data['error']['message'] ?? 'Unable to fetch Instagram account'
            );
        }

        $instagramId = $data['connected_instagram_account']['id'] ?? null;

        if ($instagramId) {
            $account->metadata = array_merge($account->metadata ?? [], [
                'instagram_id' => $instagramId,
            ]);
            $account->save();
        }

        return $data['connected_instagram_account']['id'] ?? null;
    }

    private function getMedia(SocialAccount $account): array
    {
        $instagramId = $this->getConnectedInstagramAccount($account);

        if (!$instagramId) {
            Log::warning(
                'No Instagram account connected to page ' .
                $account->platform_account_name
            );

            return [];
        }

        Log::info("Instagram ID: {$instagramId}");

        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$instagramId}/media",
            [
                'fields' => 'id,caption,media_type,timestamp',
                'limit' => 100,
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        Log::info('Instagram Media Response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            throw new \Exception(
                $data['error']['message'] ?? 'Unable to fetch media'
            );
        }

        return $data['data'] ?? [];
    }

    private function getMediaComments(SocialAccount $account, string $mediaId): array
    {
        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$mediaId}/comments",
            [
                'fields' => 'id,text,username,timestamp,from,parent_id,replies{id,text,username,timestamp,from,parent_id}',
                'limit' => 100,
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        if (!$response->successful()) {
            Log::error('Instagram media comments fetch failed', [
                'media_id' => $mediaId,
                'response' => $data,
            ]);

            return [];
        }

        return $data['data'] ?? [];
    }

    /**
     * Publish a reply to an Instagram comment
     */
    public function publishReply(SocialComment $comment, string $message, SocialAccount $account): array
    {
        try {
            $response = Http::post(
                "https://graph.facebook.com/{$this->graphVersion}/{$comment->platform_comment_id}/replies",
                [
                    'message' => $message,
                    'access_token' => $account->access_token,
                ]
            );

            $data = $response->json();

            if (!$response->successful() || isset($data['error'])) {
                throw new \Exception(
                    data_get($data, 'error.message', 'Instagram publish reply failed')
                );
            }

            $comment->update([
                'status' => 'replied',
                'ai_response_text' => $message,
                'replied_at' => now(),
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error('Instagram publish reply exception', [
                'comment_id' => $comment->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function syncSingleCommentFromWebhook(SocialAccount $account, string $commentId): ?SocialComment
    {
        $comment = $this->getSingleInstagramComment($account, $commentId);

        if (!$comment) {
            return null;
        }

        $mediaId = $comment['media']['id'] ?? null;

        $storedPost = SocialPost::firstOrCreate(
            [
                'platform_post_id' => $mediaId ?: 'unknown_' . $commentId,
                'platform' => 'instagram',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'content' => $comment['media']['caption'] ?? '',
                'posted_at' => now(),
                'raw_payload' => data_get($comment, 'media'),
            ]
        );

        $platformCommentId = $comment['id'] ?? null;
        $platformParentId = $comment['parent_id'] ?? null;

        if (!$platformCommentId) {
            return null;
        }

        /**
         * Instagram:
         * - If parent_id exists, this is a reply.
         * - If parent_id is empty/null, this is root/top-level comment.
         */
        $parentComment = null;

        if ($platformParentId) {
            $parentComment = SocialComment::where('platform', 'instagram')
                ->where('platform_comment_id', $platformParentId)
                ->first();
        }

        $rootId = null;
        $platformRootId = $platformCommentId;

        if ($parentComment) {
            $rootId = $parentComment->root_id ?: $parentComment->id;
            $platformRootId = $parentComment->platform_root_id ?: $parentComment->platform_comment_id;
        }

        $authorName = $comment['username'] ?? data_get($comment, 'from.username', 'Unknown');

        /**
         * Prefer from.id if available.
         * If not available, use username as fallback.
         */
        $authorId = data_get($comment, 'from.id')
            ?? $comment['username']
            ?? null;

        if (!$authorId) {
            Log::warning('Instagram comment missing author id', [
                'comment_id' => $platformCommentId,
                'comment' => $comment,
            ]);

            return null;
        }

        $isOwnComment = false;

        $instagramId = $account->metadata['instagram_id'] ?? null;

        if ($instagramId && $authorId === $instagramId) {
            $isOwnComment = true;
        }

        $storedComment = SocialComment::updateOrCreate(
            [
                'platform_comment_id' => $platformCommentId,
                'platform' => 'instagram',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'social_post_id' => $storedPost->id,

                'parent_id' => $parentComment?->id,
                'root_id' => $rootId,

                'platform_parent_id' => $platformParentId,
                'platform_root_id' => $platformRootId,

                'author_name' => $authorName,
                'platform_author_id' => $authorId,
                'content' => $comment['text'] ?? '',

                'direction' => $isOwnComment ? 'outbound' : 'inbound',
                'sender_type' => $isOwnComment ? 'page' : 'customer',
                'is_own_comment' => $isOwnComment,
                'raw_payload' => $comment,

                'commented_at' => isset($comment['timestamp'])
                    ? \Carbon\Carbon::parse($comment['timestamp'])->setTimezone(config('app.timezone'))
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

    private function getSingleInstagramComment(SocialAccount $account, string $commentId): ?array
    {
        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$commentId}",
            [
                'fields' => 'id,text,username,timestamp,media,parent_id,from',
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        if (!$response->successful()) {
            Log::error('Instagram single comment fetch failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $data;
    }

    private function storeInstagramManualComment(
        SocialAccount $account,
        SocialPost $storedPost,
        array $comment,
        ?SocialComment $parentComment = null
    ): ?SocialComment {
        $commentId = $comment['id'] ?? null;

        if (!$commentId) {
            return null;
        }

        $platformParentId = $comment['parent_id'] ?? null;

        if (!$platformParentId && $parentComment) {
            $platformParentId = $parentComment->platform_comment_id;
        }

        $authorName = $comment['username']
            ?? data_get($comment, 'from.username')
            ?? 'Unknown';

        $authorId = data_get($comment, 'from.id')
            ?? $comment['username']
            ?? null;

        if (!$authorId) {
            Log::warning('Instagram manual sync missing author id', [
                'comment_id' => $commentId,
                'comment' => $comment,
            ]);

            return null;
        }

        $rootId = null;
        $platformRootId = $commentId;

        if ($parentComment) {
            $rootId = $parentComment->root_id ?: $parentComment->id;
            $platformRootId = $parentComment->platform_root_id ?: $parentComment->platform_comment_id;
        }

        $isOwnComment = false;

        $instagramId = $account->metadata['instagram_id'] ?? null;

        if ($instagramId && $authorId === $instagramId) {
            $isOwnComment = true;
        }

        $storedComment = SocialComment::updateOrCreate(
            [
                'platform_comment_id' => $commentId,
                'platform' => 'instagram',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'social_post_id' => $storedPost->id,

                'parent_id' => $parentComment?->id,
                'root_id' => $rootId,

                'platform_parent_id' => $platformParentId,
                'platform_root_id' => $platformRootId,

                'author_name' => $authorName,
                'platform_author_id' => $authorId,
                'content' => $comment['text'] ?? '',

                'direction' => $isOwnComment ? 'outbound' : 'inbound',
                'sender_type' => $isOwnComment ? 'page' : 'customer',
                'is_own_comment' => $isOwnComment,

                'raw_payload' => $comment,
                'commented_at' => isset($comment['timestamp'])
                    ? \Carbon\Carbon::parse($comment['timestamp'])->setTimezone(config('app.timezone'))
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

            if ($parentComment->root_id) {
                SocialComment::where('id', $parentComment->root_id)
                    ->increment('reply_count');
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
