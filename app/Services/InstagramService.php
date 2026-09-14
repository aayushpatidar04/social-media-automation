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
        $isFullSync = $options['full_sync'] ?? false;

        // Full sync (window = 0) = no date cutoff
        if ($isFullSync || $postWindowDays === 0) {
            $postCutoff = Carbon::createFromTimestamp(0);
        } else {
            $postCutoff = now()->subDays($postWindowDays);
        }

        if ($isFullSync || $commentWindowDays === 0) {
            $commentCutoff = Carbon::createFromTimestamp(0);
        } else {
            $commentCutoff = now()->subDays($commentWindowDays);
        }

        $totalComments = 0;
        $skippedOldPosts = 0;

        $mediaList = $this->getMedia($account);

        foreach ($mediaList as $media) {
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

                    // Only dispatch AI in normal sync, skip in full sync
                    if (!$isFullSync && $this->shouldAnalyzeComment($account, $storedRootComment)) {
                        AnalyzeWithOllama::dispatch($storedRootComment);
                    }
                }

                foreach (($comment['replies']['data'] ?? []) as $reply) {
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

                        if (!$isFullSync && $this->shouldAnalyzeComment($account, $storedReply)) {
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
            'full_sync' => $isFullSync,
        ]);

        return $totalComments;
    }

    public function syncSingleCommentFromWebhook(SocialAccount $account, array $value): ?SocialComment
    {
        $commentId = $value['comment_id'] ?? null;
        $postId = $value['post_id'] ?? data_get($value, 'media_id');

        if (!$commentId || !$postId) {
            return null;
        }

        $storedPost = SocialPost::firstOrCreate(
            [
                'platform_post_id' => $postId,
                'platform' => 'instagram',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'content' => data_get($value, 'media.caption', ''),
                'posted_at' => now(),
                'raw_payload' => data_get($value, 'media'),
            ]
        );

        $parentComment = null;
        $parentId = data_get($value, 'parent_id');
        if ($parentId) {
            $parentComment = SocialComment::where('platform', 'instagram')
                ->where('platform_comment_id', $parentId)
                ->first();
        }

        $rootId = $parentComment?->root_id ?: null;
        $platformRootId = $parentComment?->platform_root_id ?? $parentComment?->platform_comment_id ?? $commentId;

        $from = data_get($value, 'from', []);
        $fromId = data_get($from, 'id');
        $fromUsername = data_get($from, 'username', 'unknown');
        $fromName = data_get($from, 'username', 'Unknown');

        $isOwnComment = $fromId === $account->platform_account_id;

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

                'platform_parent_id' => $parentId,
                'platform_root_id' => $platformRootId,

                'author_name' => $fromName,
                'platform_author_id' => $fromId,
                'author_profile_url' => 'https://instagram.com/' . $fromUsername,
                'content' => $value['text'] ?? '',

                'direction' => $isOwnComment ? 'outbound' : 'inbound',
                'sender_type' => $isOwnComment ? 'own' : 'customer',
                'is_own_comment' => $isOwnComment,

                'raw_payload' => $value,
                'commented_at' => now(),
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

        return $storedComment;
    }

    private function getMedia(SocialAccount $account): array
    {
        $response = Http::get(
            "https://graph.instagram.com/{$this->graphVersion}/{$account->platform_account_id}/media",
            [
                'fields' => 'id,caption,timestamp,media_type,media_url,permalink',
                'limit' => 25,
                'access_token' => $account->access_token,
            ]
        );

        Log::info('Instagram Media Response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $data = $response->json();

        if (!$response->successful() || isset($data['error'])) {
            throw new \Exception($data['error']['message'] ?? 'Instagram API Error');
        }

        return $data['data'] ?? [];
    }

    private function getMediaComments(SocialAccount $account, string $mediaId): array
    {
        $response = Http::get(
            "https://graph.instagram.com/{$this->graphVersion}/{$mediaId}/comments",
            [
                'fields' => 'id,text,timestamp,username,from',
                'limit' => 50,
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        if (!$response->successful() || isset($data['error'])) {
            Log::error('Instagram comments error', ['response' => $data]);
            return [];
        }

        $comments = $data['data'] ?? [];

        // Fetch replies for each comment
        foreach ($comments as &$comment) {
            $replies = $this->getCommentReplies($account, $comment['id']);
            $comment['replies'] = ['data' => $replies];
        }

        return $comments;
    }

    private function getCommentReplies(SocialAccount $account, string $commentId): array
    {
        $response = Http::get(
            "https://graph.instagram.com/{$this->graphVersion}/{$commentId}/replies",
            [
                'fields' => 'id,text,timestamp,username,from',
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        if (!$response->successful() || isset($data['error'])) {
            return [];
        }

        return $data['data'] ?? [];
    }

    public function publishReply(SocialComment $comment, string $message, SocialAccount $account)
    {
        try {
            $response = Http::post(
                "https://graph.instagram.com/{$this->graphVersion}/{$comment->platform_comment_id}/replies",
                [
                    'message' => $message,
                    'access_token' => $account->access_token,
                ]
            );

            $data = $response->json();

            if (!$response->successful() || isset($data['error'])) {
                Log::error('Instagram reply error', ['response' => $data]);
                return false;
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('Exception publishing Instagram reply: ' . $e->getMessage());
            return false;
        }
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

        $from = data_get($comment, 'from', []);
        $fromId = data_get($from, 'id');
        $fromUsername = data_get($from, 'username', 'unknown');

        if (!$fromId) {
            Log::warning('Instagram manual sync missing author', [
                'comment_id' => $commentId,
            ]);
            return null;
        }

        $isOwnComment = (string) $fromId === (string) $account->platform_account_id;

        $rootId = null;
        $platformRootId = $commentId;

        if ($parentComment) {
            $rootId = $parentComment->root_id ?: $parentComment->id;
            $platformRootId = $parentComment->platform_root_id ?: $parentComment->platform_comment_id;
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

                'platform_parent_id' => $parentComment?->platform_comment_id,
                'platform_root_id' => $platformRootId,

                'author_name' => $fromUsername,
                'platform_author_id' => $fromId,
                'author_profile_url' => 'https://instagram.com/' . $fromUsername,
                'content' => $comment['text'] ?? '',

                'direction' => $isOwnComment ? 'outbound' : 'inbound',
                'sender_type' => $isOwnComment ? 'own' : 'customer',
                'is_own_comment' => $isOwnComment,

                'raw_payload' => $comment,
                'commented_at' => isset($comment['timestamp'])
                    ? \Carbon\Carbon::parse($comment['timestamp'])
                    : now(),

                'status' => 'new',
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
