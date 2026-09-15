<?php

namespace App\Services;

use App\Jobs\AnalyzeWithOllama;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramService
{
    /**
     * Instagram Business API is accessed through the
     * Facebook Graph API using the Facebook Page access token.
     */
    protected string $graphVersion;

    public function __construct()
    {
        $this->graphVersion =
            env(
                'FACEBOOK_GRAPH_VERSION',
                'v25.0'
            );
    }

    /**
     * Sync Instagram media + comments + replies.
     */
    public function syncComments(
        SocialAccount $account,
        array $options = []
    ): int {

        $commentWindowDays =
            (int) (
                $options['comment_window_days'] ?? 7
            );

        $isFullSync =
            (bool) (
                $options['full_sync'] ?? false
            );

        $commentCutoff =
            (
                $isFullSync ||
                $commentWindowDays === 0
            )
                ? Carbon::createFromTimestamp(0)
                : now()->subDays(
                    $commentWindowDays
                );

        $totalComments = 0;

        Log::info(
            'Starting Instagram sync',
            [
                'account_id' =>
                    $account->id,

                'instagram_id' =>
                    $account->platform_account_id,

                'full_sync' =>
                    $isFullSync,
            ]
        );

        /*
         * Get Instagram media.
         */
        $mediaList =
            $this->getMedia($account);

        foreach ($mediaList as $media) {

            $mediaId =
                $media['id'] ?? null;

            if (!$mediaId) {
                continue;
            }

            $publishedAt =
                $media['timestamp'] ?? null;

            /*
             * Store/update post.
             */
            $storedPost =
                SocialPost::updateOrCreate(
                    [
                        'platform_post_id' =>
                            $mediaId,

                        'platform' =>
                            'instagram',
                    ],
                    [
                        'organization_id' =>
                            $account->organization_id,

                        'social_account_id' =>
                            $account->id,

                        'content' =>
                            $media['caption'] ?? '',

                        'posted_at' =>
                            $publishedAt
                                ? Carbon::parse(
                                    $publishedAt
                                )
                                : now(),

                        'raw_payload' =>
                            $media,
                    ]
                );

            /*
             * Fetch comments.
             */
            $comments =
                $this->getMediaComments(
                    $account,
                    $mediaId
                );

            foreach ($comments as $comment) {

                $commentedAt =
                    $comment['timestamp']
                    ?? null;

                /*
                 * Respect comment window.
                 */
                if (
                    $commentedAt &&
                    Carbon::parse(
                        $commentedAt
                    )->lt($commentCutoff)
                ) {
                    continue;
                }

                /*
                 * Store root comment.
                 */
                $storedRootComment =
                    $this->storeInstagramManualComment(
                        account: $account,
                        storedPost: $storedPost,
                        comment: $comment,
                        parentComment: null
                    );

                if (
                    $storedRootComment?->wasRecentlyCreated
                ) {

                    $totalComments++;

                    /*
                     * Full sync = no AI.
                     */
                    if (
                        !$isFullSync &&
                        $this->shouldAnalyzeComment(
                            $account,
                            $storedRootComment
                        )
                    ) {

                        AnalyzeWithOllama::dispatch(
                            $storedRootComment
                        );
                    }
                }

                /*
                 * Store replies.
                 */
                foreach (
                    ($comment['replies']['data'] ?? [])
                    as $reply
                ) {

                    $replyAt =
                        $reply['timestamp']
                        ?? null;

                    if (
                        $replyAt &&
                        Carbon::parse(
                            $replyAt
                        )->lt($commentCutoff)
                    ) {
                        continue;
                    }

                    $storedReply =
                        $this->storeInstagramManualComment(
                            account: $account,
                            storedPost: $storedPost,
                            comment: $reply,
                            parentComment:
                                $storedRootComment
                        );

                    if (
                        $storedReply?->wasRecentlyCreated
                    ) {

                        $totalComments++;

                        if (
                            !$isFullSync &&
                            $this->shouldAnalyzeComment(
                                $account,
                                $storedReply
                            )
                        ) {

                            AnalyzeWithOllama::dispatch(
                                $storedReply
                            );
                        }
                    }
                }
            }
        }

        $account->update([
            'last_synced_at' => now(),
        ]);

        Log::info(
            'Instagram sync completed',
            [
                'account_id' =>
                    $account->id,

                'total_comments' =>
                    $totalComments,

                'full_sync' =>
                    $isFullSync,
            ]
        );

        return $totalComments;
    }

    /**
     * Sync a single Instagram comment received from webhook.
     */
    public function syncSingleCommentFromWebhook(
        SocialAccount $account,
        array $value
    ): ?SocialComment {

        $commentId =
            $value['comment_id']
            ?? $value['id']
            ?? null;

        $postId =
            $value['post_id']
            ?? $value['media_id']
            ?? data_get(
                $value,
                'media.id'
            );

        if (!$commentId || !$postId) {
            return null;
        }

        /*
         * Store post if necessary.
         */
        $storedPost =
            SocialPost::firstOrCreate(
                [
                    'platform_post_id' =>
                        $postId,

                    'platform' =>
                        'instagram',
                ],
                [
                    'organization_id' =>
                        $account->organization_id,

                    'social_account_id' =>
                        $account->id,

                    'content' =>
                        data_get(
                            $value,
                            'media.caption',
                            ''
                        ),

                    'posted_at' =>
                        now(),

                    'raw_payload' =>
                        data_get(
                            $value,
                            'media'
                        ),
                ]
            );

        /*
         * Find parent.
         */
        $parentId =
            data_get(
                $value,
                'parent_id'
            );

        $parentComment = null;

        if ($parentId) {

            $parentComment =
                SocialComment::where(
                    'platform',
                    'instagram'
                )
                    ->where(
                        'platform_comment_id',
                        $parentId
                    )
                    ->first();
        }

        /*
         * Root information.
         */
        $rootId =
            $parentComment?->root_id;

        $platformRootId =
            $parentComment?->platform_root_id
            ?? $parentComment?->platform_comment_id
            ?? $commentId;

        /*
         * Author.
         */
        $from =
            data_get(
                $value,
                'from',
                []
            );

        $fromId =
            data_get(
                $from,
                'id'
            );

        $fromUsername =
            data_get(
                $from,
                'username',
                data_get(
                    $value,
                    'username',
                    'unknown'
                )
            );

        $fromName =
            data_get(
                $from,
                'username',
                $fromUsername
            );

        /*
         * Own comment.
         */
        $isOwnComment =
            $fromId
                ? (string) $fromId ===
                    (string) $account->platform_account_id
                : false;

        /*
         * Store comment.
         */
        $storedComment =
            SocialComment::updateOrCreate(
                [
                    'platform_comment_id' =>
                        $commentId,

                    'platform' =>
                        'instagram',
                ],
                [
                    'organization_id' =>
                        $account->organization_id,

                    'social_account_id' =>
                        $account->id,

                    'social_post_id' =>
                        $storedPost->id,

                    'parent_id' =>
                        $parentComment?->id,

                    'root_id' =>
                        $rootId,

                    'platform_parent_id' =>
                        $parentId,

                    'platform_root_id' =>
                        $platformRootId,

                    'author_name' =>
                        $fromName,

                    'platform_author_id' =>
                        $fromId,

                    'author_profile_url' =>
                        $fromUsername !== 'unknown'
                            ? 'https://instagram.com/'
                                . $fromUsername
                            : null,

                    'content' =>
                        $value['text']
                        ?? $value['message']
                        ?? '',

                    'direction' =>
                        $isOwnComment
                            ? 'outbound'
                            : 'inbound',

                    'sender_type' =>
                        $isOwnComment
                            ? 'own'
                            : 'customer',

                    'is_own_comment' =>
                        $isOwnComment,

                    'raw_payload' =>
                        $value,

                    'commented_at' =>
                        isset(
                            $value['timestamp']
                        )
                            ? Carbon::parse(
                                $value['timestamp']
                            )->setTimezone(
                                config('app.timezone')
                            )
                            : now(),

                    'status' =>
                        $isOwnComment
                            ? 'sent'
                            : 'new',
                ]
            );

        /*
         * Root comment points to itself.
         */
        if (!$storedComment->root_id) {

            $storedComment->update([
                'root_id' =>
                    $storedComment->id,

                'platform_root_id' =>
                    $storedComment->platform_comment_id,
            ]);
        }

        /*
         * Reply count.
         */
        if (
            $parentComment &&
            $storedComment->wasRecentlyCreated
        ) {

            $parentComment->increment(
                'reply_count'
            );

            if ($storedComment->root_id) {

                SocialComment::where(
                    'id',
                    $storedComment->root_id
                )->increment(
                    'reply_count'
                );
            }
        }

        /*
         * IMPORTANT:
         *
         * Webhook comments should also respect
         * auto_reply_started_at.
         */
        if (
            $storedComment->wasRecentlyCreated &&
            $this->shouldAnalyzeComment(
                $account,
                $storedComment
            )
        ) {

            AnalyzeWithOllama::dispatch(
                $storedComment
            );
        }

        return $storedComment;
    }

    /**
     * Fetch full Instagram comment data.
     *
     * Uses Facebook Graph API because this is a
     * Facebook Page-connected Instagram Business account.
     */
    public function fetchCommentData(
        SocialAccount $account,
        string $commentId
    ): ?array {

        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/"
            . $commentId,
            [
                'fields' =>
                    'id,text,timestamp,from,username,'
                    . 'media,parent_id',

                'access_token' =>
                    $account->access_token,
            ]
        );

        if (!$response->successful()) {

            Log::warning(
                'Instagram fetch comment failed',
                [
                    'comment_id' =>
                        $commentId,

                    'status' =>
                        $response->status(),

                    'response' =>
                        $response->json(),
                ]
            );

            return null;
        }

        $data =
            $response->json();

        if (isset($data['error'])) {
            return null;
        }

        /*
         * Fetch media information.
         */
        $mediaId =
            $data['media']['id']
            ?? $data['media_id']
            ?? null;

        if (
            $mediaId &&
            !isset($data['media']['caption'])
        ) {

            $mediaResponse =
                Http::get(
                    "https://graph.facebook.com/"
                    . $this->graphVersion
                    . "/{$mediaId}",
                    [
                        'fields' =>
                            'id,caption,permalink,'
                            . 'media_type,thumbnail_url',

                        'access_token' =>
                            $account->access_token,
                    ]
                );

            if ($mediaResponse->successful()) {

                $data['media'] =
                    $mediaResponse->json();
            }
        }

        return $data;
    }

    /**
     * Get Instagram Business media.
     *
     * IMPORTANT:
     * Facebook Page access token + Facebook Graph API.
     */
    private function getMedia(
        SocialAccount $account
    ): array {

        $response =
            Http::get(
                "https://graph.facebook.com/{$this->graphVersion}/"
                . $account->platform_account_id
                . '/media',
                [
                    'fields' =>
                        'id,caption,timestamp,media_type,'
                        . 'media_url,permalink',

                    'limit' => 25,

                    'access_token' =>
                        $account->access_token,
                ]
            );

        Log::info(
            'Instagram Media Response',
            [
                'account_id' =>
                    $account->id,

                'instagram_id' =>
                    $account->platform_account_id,

                'status' =>
                    $response->status(),

                'body' =>
                    $response->body(),
            ]
        );

        $data =
            $response->json();

        if (
            !$response->successful() ||
            isset($data['error'])
        ) {

            throw new \Exception(
                $data['error']['message']
                ?? 'Instagram API Error'
            );
        }

        return $data['data'] ?? [];
    }

    /**
     * Get comments for Instagram media.
     */
    private function getMediaComments(
        SocialAccount $account,
        string $mediaId
    ): array {

        $response =
            Http::get(
                "https://graph.facebook.com/{$this->graphVersion}/"
                . $mediaId
                . '/comments',
                [
                    'fields' =>
                        'id,text,timestamp,username,from,parent_id',

                    'limit' => 50,

                    'access_token' =>
                        $account->access_token,
                ]
            );

        $data =
            $response->json();

        if (
            !$response->successful() ||
            isset($data['error'])
        ) {

            Log::error(
                'Instagram comments error',
                [
                    'media_id' =>
                        $mediaId,

                    'response' =>
                        $data,
                ]
            );

            return [];
        }

        $comments =
            $data['data'] ?? [];

        /*
         * Fetch replies separately.
         */
        foreach ($comments as &$comment) {

            $comment['replies'] = [
                'data' =>
                    $this->getCommentReplies(
                        $account,
                        $comment['id']
                    ),
            ];
        }

        unset($comment);

        return $comments;
    }

    /**
     * Fetch Instagram comment replies.
     */
    private function getCommentReplies(
        SocialAccount $account,
        string $commentId
    ): array {

        $response =
            Http::get(
                "https://graph.facebook.com/{$this->graphVersion}/"
                . $commentId
                . '/replies',
                [
                    'fields' =>
                        'id,text,timestamp,username,from,parent_id',

                    'limit' => 50,

                    'access_token' =>
                        $account->access_token,
                ]
            );

        $data =
            $response->json();

        if (
            !$response->successful() ||
            isset($data['error'])
        ) {

            Log::warning(
                'Instagram replies error',
                [
                    'comment_id' =>
                        $commentId,

                    'response' =>
                        $data,
                ]
            );

            return [];
        }

        return $data['data'] ?? [];
    }

    /**
     * Publish Instagram reply.
     */
    public function publishReply(
        SocialComment $comment,
        string $message,
        SocialAccount $account
    ) {

        try {

            $response =
                Http::post(
                    "https://graph.facebook.com/"
                    . $this->graphVersion
                    . "/{$comment->platform_comment_id}/replies",
                    [
                        'message' =>
                            $message,

                        'access_token' =>
                            $account->access_token,
                    ]
                );

            $data =
                $response->json();

            if (
                !$response->successful() ||
                isset($data['error'])
            ) {

                Log::error(
                    'Instagram reply error',
                    [
                        'comment_id' =>
                            $comment->id,

                        'response' =>
                            $data,
                    ]
                );

                return false;
            }

            return $data;

        } catch (\Throwable $e) {

            Log::error(
                'Exception publishing Instagram reply',
                [
                    'comment_id' =>
                        $comment->id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /**
     * Store Instagram comment from manual sync.
     */
    private function storeInstagramManualComment(
        SocialAccount $account,
        SocialPost $storedPost,
        array $comment,
        ?SocialComment $parentComment = null
    ): ?SocialComment {

        $commentId =
            $comment['id'] ?? null;

        if (!$commentId) {
            return null;
        }

        $from =
            data_get(
                $comment,
                'from',
                []
            );

        $fromId =
            data_get(
                $from,
                'id'
            );

        $fromUsername =
            data_get(
                $from,
                'username',
                $comment['username'] ?? 'unknown'
            );

        /*
         * Instagram can occasionally omit author information.
         *
         * Do not discard the comment.
         */
        if (!$fromId) {

            Log::warning(
                'Instagram manual sync missing author',
                [
                    'comment_id' =>
                        $commentId,

                    'comment' =>
                        $comment,
                ]
            );
        }

        /*
         * Own comment.
         */
        $isOwnComment =
            $fromId
                ? (string) $fromId ===
                    (string) $account->platform_account_id
                : false;

        /*
         * Thread root.
         */
        $rootId = null;

        $platformRootId =
            $commentId;

        if ($parentComment) {

            $rootId =
                $parentComment->root_id
                ?: $parentComment->id;

            $platformRootId =
                $parentComment->platform_root_id
                ?: $parentComment->platform_comment_id;
        }

        /*
         * Store/update.
         */
        $storedComment =
            SocialComment::updateOrCreate(
                [
                    'platform_comment_id' =>
                        $commentId,

                    'platform' =>
                        'instagram',
                ],
                [
                    'organization_id' =>
                        $account->organization_id,

                    'social_account_id' =>
                        $account->id,

                    'social_post_id' =>
                        $storedPost->id,

                    'parent_id' =>
                        $parentComment?->id,

                    'root_id' =>
                        $rootId,

                    'platform_parent_id' =>
                        $parentComment?->platform_comment_id,

                    'platform_root_id' =>
                        $platformRootId,

                    'author_name' =>
                        $fromUsername,

                    'platform_author_id' =>
                        $fromId,

                    'author_profile_url' =>
                        $fromUsername !== 'unknown'
                            ? 'https://instagram.com/'
                                . $fromUsername
                            : null,

                    'content' =>
                        $comment['text']
                        ?? $comment['message']
                        ?? '',

                    'direction' =>
                        $isOwnComment
                            ? 'outbound'
                            : 'inbound',

                    'sender_type' =>
                        $isOwnComment
                            ? 'own'
                            : 'customer',

                    'is_own_comment' =>
                        $isOwnComment,

                    'raw_payload' =>
                        $comment,

                    'commented_at' =>
                        isset(
                            $comment['timestamp']
                        )
                            ? Carbon::parse(
                                $comment['timestamp']
                            )->setTimezone(
                                config('app.timezone')
                            )
                            : now(),

                    'status' =>
                        $isOwnComment
                            ? 'sent'
                            : 'new',
                ]
            );

        /*
         * Root comment points to itself.
         */
        if (!$storedComment->root_id) {

            $storedComment->update([
                'root_id' =>
                    $storedComment->id,

                'platform_root_id' =>
                    $storedComment->platform_comment_id,
            ]);
        }

        /*
         * Increment reply counts only for new replies.
         */
        if (
            $parentComment &&
            $storedComment->wasRecentlyCreated
        ) {

            $parentComment->increment(
                'reply_count'
            );

            if ($storedComment->root_id) {

                SocialComment::where(
                    'id',
                    $storedComment->root_id
                )->increment(
                    'reply_count'
                );
            }
        }

        return $storedComment;
    }

    /**
     * Determine whether a comment should be analyzed.
     */
    private function shouldAnalyzeComment(
        SocialAccount $account,
        SocialComment $comment
    ): bool {

        /*
         * Existing comments should never be analyzed again.
         */
        if (!$comment->wasRecentlyCreated) {
            return false;
        }

        /*
         * Never analyze our own comments.
         */
        if ($comment->is_own_comment) {
            return false;
        }

        /*
         * Automation must have been started.
         */
        if (!$account->auto_reply_started_at) {
            return false;
        }

        if (!$comment->commented_at) {
            return false;
        }

        /*
         * Only analyze comments received after
         * automation was enabled.
         */
        return $comment->commented_at->gte(
            $account->auto_reply_started_at
        );
    }
}