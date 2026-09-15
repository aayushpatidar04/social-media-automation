<?php

namespace App\Services;

use App\Jobs\AnalyzeWithOllama;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookService
{
    private string $graphVersion;

    public function __construct()
    {
        $this->graphVersion = env('FACEBOOK_GRAPH_VERSION', 'v25.0');
    }

    /**
     * Sync Facebook Page posts + comments + replies.
     *
     * Historical/full sync:
     * - Store everything
     * - Do NOT trigger Ollama
 *
     * Normal sync:
     * - Store new comments
     * - Analyze only comments after auto_reply_started_at
     */
    public function syncPageComments(
        SocialAccount $account,
        array $options = []
    ): int {
        try {
            $fullSync = (bool) ($options['full_sync'] ?? false);

            $commentWindowDays = (int) (
                $options['comment_window_days'] ?? 7
            );

            Log::info(
                'Starting sync for account: '
                . $account->platform_account_name
                . ' (full_sync: '
                . ($fullSync ? 'yes' : 'no')
                . ')'
            );

            /*
             * Full sync:
             *     beginning of Unix time
             *
             * Normal sync:
             *     only comments within configured window
             */
            $commentCutoff = (
                $fullSync || $commentWindowDays === 0
            )
                ? Carbon::createFromTimestamp(0)
                : now()->subDays($commentWindowDays);

            $totalComments = 0;

            /*
             * Fetch Page posts.
             */
            $posts = $this->getPagePosts($account);

            Log::info(
                'Found ' . count($posts) . ' posts'
            );

            foreach ($posts as $post) {

                $postId = $post['id'] ?? null;

                if (!$postId) {
                    continue;
                }

                $publishedAt = $post['created_time'] ?? null;

                /*
                 * Store/update post.
                 */
                $storedPost = SocialPost::updateOrCreate(
                    [
                        'platform_post_id' => $postId,
                        'platform' => 'facebook',
                    ],
                    [
                        'organization_id' => $account->organization_id,
                        'social_account_id' => $account->id,
                        'content' => $post['message'] ?? '',
                        'posted_at' => $publishedAt
                            ? Carbon::parse($publishedAt)
                            : now(),
                        'raw_payload' => $post,
                    ]
                );

                /*
                 * Fetch top-level comments.
                 */
                $comments = $this->getPostComments(
                    $account,
                    $postId
                );

                foreach ($comments as $comment) {

                    $commentedAt = $comment['created_time'] ?? null;

                    /*
                     * Respect comment window.
                     */
                    if (
                        $commentedAt &&
                        Carbon::parse($commentedAt)->lt($commentCutoff)
                    ) {
                        continue;
                    }

                    /*
                     * Store top-level comment.
                     */
                    $storedRootComment =
                        $this->storeFacebookManualComment(
                            account: $account,
                            storedPost: $storedPost,
                            comment: $comment,
                            postId: $postId,
                            parentComment: null
                        );

                    if ($storedRootComment?->wasRecentlyCreated) {

                        $totalComments++;

                        /*
                         * IMPORTANT:
                         *
                         * Full sync NEVER analyzes.
                         *
                         * Normal sync analyzes only if:
                         * - new
                         * - customer comment
                         * - after auto_reply_started_at
                         */
                        if (
                            !$fullSync &&
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
                     * Fetch/store replies.
                     *
                     * getPostComments() now fetches replies explicitly,
                     * so this remains the same structure.
                     */
                    foreach (
                        ($comment['comments']['data'] ?? [])
                        as $reply
                    ) {

                        $replyAt = $reply['created_time'] ?? null;

                        if (
                            $replyAt &&
                            Carbon::parse($replyAt)->lt($commentCutoff)
                        ) {
                            continue;
                        }

                        $storedReply =
                            $this->storeFacebookManualComment(
                                account: $account,
                                storedPost: $storedPost,
                                comment: $reply,
                                postId: $postId,
                                parentComment: $storedRootComment
                            );

                        if ($storedReply?->wasRecentlyCreated) {

                            $totalComments++;

                            if (
                                !$fullSync &&
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

            /*
             * Update last sync time.
             */
            $account->update([
                'last_synced_at' => now(),
            ]);

            /*
             * Also sync linked Instagram Business account.
             */
            $this->syncLinkedInstagram(
                $account,
                $options
            );

            Log::info('Facebook sync completed', [
                'account_id' => $account->id,
                'total_comments' => $totalComments,
                'full_sync' => $fullSync,
            ]);

            return $totalComments;

        } catch (\Throwable $e) {

            Log::error(
                'Facebook sync error for account '
                . $account->id,
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Sync the Instagram Business account linked to this Facebook Page.
     */
    private function syncLinkedInstagram(
        SocialAccount $facebookAccount,
        array $options = []
    ): void {
        try {

            $igAccountId =
                $this->getLinkedInstagramAccountId(
                    $facebookAccount
                );

            if (!$igAccountId) {

                Log::info(
                    'No linked Instagram account found for Facebook page',
                    [
                        'account_id' => $facebookAccount->id,
                    ]
                );

                return;
            }

            /*
             * Find existing Instagram account.
             */
            $igAccount = SocialAccount::where(
                'platform',
                'instagram'
            )
                ->where(
                    'platform_account_id',
                    $igAccountId
                )
                ->where(
                    'organization_id',
                    $facebookAccount->organization_id
                )
                ->first();

            /*
             * Create Instagram account if it doesn't exist.
             *
             * IMPORTANT:
             *
             * We intentionally use the Facebook Page access token
             * because this Instagram Business account is connected
             * through the Facebook Page.
             */
            if (!$igAccount) {

                $igAccount = SocialAccount::create([
                    'organization_id' =>
                        $facebookAccount->organization_id,

                    'user_id' =>
                        $facebookAccount->user_id,

                    'platform' => 'instagram',

                    'platform_account_id' =>
                        $igAccountId,

                    'platform_account_name' =>
                        'Instagram (' . $igAccountId . ')',

                    'platform_account_handle' => '',

                    'access_token' =>
                        $facebookAccount->access_token,

                    'refresh_token' =>
                        $facebookAccount->refresh_token,

                    'token_expires_at' =>
                        $facebookAccount->token_expires_at,

                    'status' => 'connected',

                    'is_active' => true,

                    'metadata' => [
                        'linked_to_facebook' =>
                            $facebookAccount->id,

                        'facebook_page_id' =>
                            $facebookAccount->platform_account_id,
                    ],
                ]);

                Log::info(
                    'Created Instagram account linked to Facebook page',
                    [
                        'instagram_id' => $igAccount->id,
                        'facebook_id' => $facebookAccount->id,
                        'ig_business_id' => $igAccountId,
                    ]
                );

            } else {

                /*
                 * IMPORTANT:
                 *
                 * Existing linked Instagram accounts may have an old
                 * invalid token copied from an earlier implementation.
                 *
                 * Keep it synchronized with the Facebook Page token.
                 */
                $igAccount->update([
                    'access_token' =>
                        $facebookAccount->access_token,

                    'refresh_token' =>
                        $facebookAccount->refresh_token,

                    'token_expires_at' =>
                        $facebookAccount->token_expires_at,
                ]);
            }

            /*
             * Sync Instagram comments.
             */
            $instagramService =
                new InstagramService();

            $igOptions = array_merge(
                $options,
                [
                    'full_sync' =>
                        $options['full_sync'] ?? false,
                ]
            );

            $igCount =
                $instagramService->syncComments(
                    $igAccount,
                    $igOptions
                );

            Log::info(
                'Instagram sync completed (via Facebook link)',
                [
                    'instagram_id' => $igAccount->id,
                    'new_comments' => $igCount,
                    'linked_to_facebook' =>
                        $facebookAccount->id,
                ]
            );

        } catch (\Throwable $e) {

            /*
             * Don't make a Facebook sync fail just because
             * Instagram failed.
             */
            Log::warning(
                'Linked Instagram sync failed',
                [
                    'facebook_id' =>
                        $facebookAccount->id,

                    'error' =>
                        $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Get Instagram Business account linked to Facebook Page.
     */
    public function getLinkedInstagramAccountId(
        SocialAccount $account
    ): ?string {

        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/"
            . $account->platform_account_id,
            [
                'fields' =>
                    'instagram_business_account',

                'access_token' =>
                    $account->access_token,
            ]
        );

        if (!$response->successful()) {

            Log::warning(
                'Failed to fetch Instagram link for Facebook page',
                [
                    'account_id' => $account->id,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]
            );

            return null;
        }

        $igData =
            $response->json('instagram_business_account');

        if (
            !$igData ||
            !isset($igData['id'])
        ) {

            Log::info(
                'No Instagram Business account linked to this Facebook page',
                [
                    'account_id' => $account->id,
                ]
            );

            return null;
        }

        Log::info(
            'Found linked Instagram Business account',
            [
                'facebook_id' => $account->id,
                'ig_business_id' => $igData['id'],
            ]
        );

        return (string) $igData['id'];
    }

    /**
     * Get Facebook Page posts.
     */
    private function getPagePosts(
        SocialAccount $account
    ): array {

        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/"
            . $account->platform_account_id
            . '/posts',
            [
                'limit' => 100,

                'fields' =>
                    'id,message,created_time,'
                    . 'from,permalink_url',

                'access_token' =>
                    $account->access_token,
            ]
        );

        $data = $response->json();

        if (!$response->successful()) {

            throw new \Exception(
                $data['error']['message']
                ?? 'Facebook API Error'
            );
        }

        return $data['data'] ?? [];
    }

    /**
     * Get top-level comments for a Facebook post.
     *
     * Replies are fetched separately because Facebook may omit
     * the author information from nested comments.
     */
    private function getPostComments(
        SocialAccount $account,
        string $postId
    ): array {

        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/"
            . $postId
            . '/comments',
            [
                'fields' =>
                    'id,message,created_time,from,parent',

                'summary' => 'total_count',

                'limit' => 100,

                'access_token' =>
                    $account->access_token,
            ]
        );

        $data = $response->json();

        if (
            !$response->successful() ||
            isset($data['error'])
        ) {

            Log::error(
                'Error fetching comments for post '
                . $postId,
                [
                    'response' => $data,
                ]
            );

            return [];
        }

        $comments = $data['data'] ?? [];

        /*
         * Fetch replies individually.
         */
        foreach ($comments as &$comment) {

            $comment['comments'] = [
                'data' => $this->getCommentReplies(
                    $account,
                    $comment['id']
                ),
            ];
        }

        unset($comment);

        return $comments;
    }

    /**
     * Fetch direct replies for a Facebook comment.
     */
    private function getCommentReplies(
        SocialAccount $account,
        string $commentId
    ): array {

        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/"
            . $commentId
            . '/comments',
            [
                'fields' =>
                    'id,message,created_time,from,parent',

                'limit' => 100,

                'access_token' =>
                    $account->access_token,
            ]
        );

        $data = $response->json();

        if (
            !$response->successful() ||
            isset($data['error'])
        ) {

            Log::warning(
                'Error fetching Facebook comment replies',
                [
                    'comment_id' => $commentId,
                    'response' => $data,
                ]
            );

            return [];
        }

        return $data['data'] ?? [];
    }

    /**
     * Publish a reply to a Facebook comment.
     */
    public function publishReply(
        SocialComment $comment,
        string $message,
        SocialAccount $account
    ) {
        try {

            $response = Http::post(
                "https://graph.facebook.com/{$this->graphVersion}/"
                . $comment->platform_comment_id
                . '/comments',
                [
                    'message' => $message,

                    'access_token' =>
                        $account->access_token,
                ]
            );

            $data = $response->json();

            if (
                !$response->successful() ||
                isset($data['error'])
            ) {

                Log::error(
                    'Error publishing Facebook reply',
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
                'Exception publishing Facebook reply',
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
     * Handle Facebook comment webhook.
     */
    public function syncSingleCommentFromWebhook(
        SocialAccount $account,
        array $value
    ): ?SocialComment {

        $commentId =
            $value['comment_id'] ?? null;

        $postId =
            $value['post_id'] ?? null;

        $parentPlatformId =
            $value['parent_id'] ?? null;

        if (!$commentId || !$postId) {
            return null;
        }

        /*
         * Store post if webhook doesn't already have it.
         */
        $storedPost = SocialPost::firstOrCreate(
            [
                'platform_post_id' => $postId,
                'platform' => 'facebook',
            ],
            [
                'organization_id' =>
                    $account->organization_id,

                'social_account_id' =>
                    $account->id,

                'content' =>
                    data_get(
                        $value,
                        'post.message',
                        ''
                    ),

                'posted_at' => now(),

                'raw_payload' =>
                    data_get(
                        $value,
                        'post'
                    ),
            ]
        );

        /*
         * Find parent comment.
         */
        $isTopLevelComment =
            $parentPlatformId === $postId;

        $parentComment = null;

        if (
            !$isTopLevelComment &&
            $parentPlatformId
        ) {

            $parentComment =
                SocialComment::where(
                    'platform',
                    'facebook'
                )
                    ->where(
                        'platform_comment_id',
                        $parentPlatformId
                    )
                    ->first();
        }

        /*
         * Determine root.
         */
        $rootId = null;

        $platformRootId = $commentId;

        if ($parentComment) {

            $rootId =
                $parentComment->root_id
                ?: $parentComment->id;

            $platformRootId =
                $parentComment->platform_root_id
                ?: $parentComment->platform_comment_id;
        }

        $fromId =
            data_get($value, 'from.id');

        $fromName =
            data_get(
                $value,
                'from.name',
                'Unknown'
            );

        /*
         * Do NOT reject webhook comments if Facebook
         * doesn't provide from.id.
         */
        $isOwnComment = $fromId
            ? (string) $fromId ===
                (string) $account->platform_account_id
            : false;

        $storedComment =
            SocialComment::updateOrCreate(
                [
                    'platform_comment_id' =>
                        $commentId,

                    'platform' =>
                        'facebook',
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
                        $parentPlatformId,

                    'platform_root_id' =>
                        $platformRootId,

                    'author_name' =>
                        $fromName,

                    'platform_author_id' =>
                        $fromId,

                    'content' =>
                        $value['message'] ?? '',

                    'direction' =>
                        $isOwnComment
                            ? 'outbound'
                            : 'inbound',

                    'sender_type' =>
                        $isOwnComment
                            ? 'page'
                            : 'customer',

                    'is_own_comment' =>
                        $isOwnComment,

                    'raw_payload' =>
                        $value,

                    'commented_at' =>
                        isset($value['created_time'])
                            ? Carbon::parse(
                                $value['created_time']
                            )->setTimezone(
                                config('app.timezone')
                            )
                            : now(),
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
         * Increment reply counters only for newly-created replies.
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
         * Analyze only eligible inbound comments.
         */
        if (
            $storedComment->wasRecentlyCreated &&
            $this->shouldAnalyzeComment(
                $account,
                $storedComment
            )
        ) {

            $storedComment->update([
                'status' => 'new',
            ]);

            AnalyzeWithOllama::dispatch(
                $storedComment
            );
        }

        return $storedComment;
    }

    /**
     * Store Facebook comment from manual/full sync.
     */
    private function storeFacebookManualComment(
        SocialAccount $account,
        SocialPost $storedPost,
        array $comment,
        string $postId,
        ?SocialComment $parentComment = null
    ): ?SocialComment {

        $commentId =
            $comment['id'] ?? null;

        if (!$commentId) {
            return null;
        }

        /*
         * Facebook may omit "from" for nested replies.
         *
         * IMPORTANT:
         * We no longer reject the comment.
         */
        $fromId =
            data_get(
                $comment,
                'from.id'
            );

        $fromName =
            data_get(
                $comment,
                'from.name',
                'Unknown'
            );

        if (!$fromId) {

            Log::warning(
                'Facebook manual sync missing author id',
                [
                    'comment_id' =>
                        $commentId,

                    'comment' =>
                        $comment,
                ]
            );
        }

        /*
         * Parent platform ID.
         */
        $platformParentId =
            data_get(
                $comment,
                'parent.id'
            );

        if (
            !$platformParentId &&
            $parentComment
        ) {

            $platformParentId =
                $parentComment->platform_comment_id;
        }

        /*
         * Top-level comment points to post.
         */
        if (!$platformParentId) {
            $platformParentId = $postId;
        }

        /*
         * Thread root.
         */
        $rootId = null;

        $platformRootId = $commentId;

        if ($parentComment) {

            $rootId =
                $parentComment->root_id
                ?: $parentComment->id;

            $platformRootId =
                $parentComment->platform_root_id
                ?: $parentComment->platform_comment_id;
        }

        /*
         * If author ID is unavailable we cannot safely determine
         * ownership, so treat it as inbound.
         */
        $isOwnComment = $fromId
            ? (string) $fromId ===
                (string) $account->platform_account_id
            : false;

        /*
         * Store/update comment.
         */
        $storedComment =
            SocialComment::updateOrCreate(
                [
                    'platform_comment_id' =>
                        $commentId,

                    'platform' =>
                        'facebook',
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
                        $platformParentId,

                    'platform_root_id' =>
                        $platformRootId,

                    'author_name' =>
                        $fromName,

                    'platform_author_id' =>
                        $fromId,

                    'content' =>
                        $comment['message'] ?? '',

                    'direction' =>
                        $isOwnComment
                            ? 'outbound'
                            : 'inbound',

                    'sender_type' =>
                        $isOwnComment
                            ? 'page'
                            : 'customer',

                    'is_own_comment' =>
                        $isOwnComment,

                    'raw_payload' =>
                        $comment,

                    'commented_at' =>
                        isset(
                            $comment['created_time']
                        )
                            ? Carbon::parse(
                                $comment['created_time']
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
         * Increment reply counts only when the reply is newly inserted.
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
     * Determine whether a newly-created comment can be analyzed.
     *
     * This prevents old comments from triggering AI after the first sync.
     */
    private function shouldAnalyzeComment(
        SocialAccount $account,
        SocialComment $comment
    ): bool {

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
         * Automation must have been explicitly started.
         */
        if (!$account->auto_reply_started_at) {
            return false;
        }

        if (!$comment->commented_at) {
            return false;
        }

        /*
         * Only comments after automation was enabled.
         */
        return $comment->commented_at->gte(
            $account->auto_reply_started_at
        );
    }

    /**
     * Store Facebook post received from webhook.
     */
    public function syncSinglePostFromWebhook(
        SocialAccount $account,
        array $value
    ): ?SocialPost {

        $postId =
            $value['post_id']
            ?? data_get(
                $value,
                'post.id'
            );

        if (!$postId) {

            Log::warning(
                'Facebook post webhook missing post_id',
                [
                    'value' => $value,
                ]
            );

            return null;
        }

        $storedPost =
            SocialPost::updateOrCreate(
                [
                    'platform_post_id' =>
                        $postId,

                    'platform' =>
                        'facebook',
                ],
                [
                    'organization_id' =>
                        $account->organization_id,

                    'social_account_id' =>
                        $account->id,

                    'content' =>
                        data_get(
                            $value,
                            'post.message',
                            $value['message'] ?? ''
                        ),

                    'posted_at' =>
                        isset($value['created_time'])
                            ? Carbon::parse(
                                $value['created_time']
                            )
                            : now(),

                    'raw_payload' =>
                        data_get(
                            $value,
                            'post',
                            $value
                        ),
                ]
            );

        Log::info(
            'Facebook webhook post synced',
            [
                'post_id' =>
                    $storedPost->id,

                'platform_post_id' =>
                    $postId,

                'item' =>
                    $value['item'] ?? null,
            ]
        );

        return $storedPost;
    }
}