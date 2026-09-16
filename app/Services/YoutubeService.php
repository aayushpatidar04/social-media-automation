<?php

namespace App\Services;

use App\Jobs\AnalyzeWithOllama;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class YoutubeService
{
    private string $baseUrl = 'https://www.googleapis.com/youtube/v3';

    public function exchangeCodeForToken(string $code): array
    {
        $clientId = env('YOUTUBE_CLIENT_ID');
        $clientSecret = env('YOUTUBE_CLIENT_SECRET');
        $redirectUri = env('YOUTUBE_REDIRECT_URI');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (!$response->successful()) {
            throw new \Exception('YouTube token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public function getMyChannel(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)->get("{$this->baseUrl}/channels", [
            'part' => 'snippet,contentDetails,statistics',
            'mine' => 'true',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to fetch YouTube channel: ' . $response->body());
        }

        $items = $response->json('items');

        return $items[0] ?? null;
    }

    public function syncComments(SocialAccount $account, array $options = []): int
    {
        $accessToken = $this->validToken($account);
        $videos = $this->getVideos($account, $accessToken);

        $commentWindowDays = $options['comment_window_days'] ?? 7;
        $isFullSync = $options['full_sync'] ?? false;

        // NEVER skip posts by age — a fresh comment on an old post must be caught
        $totalNew = 0;

        foreach ($videos as $video) {
            $videoId = $video['id']['videoId']
                ?? $video['snippet']['resourceId']['videoId']
                ?? null;

            if (!$videoId) {
                continue;
            }

            $totalNew += $this->syncNewCommentsForVideo($account, $videoId, $accessToken, $commentWindowDays, $isFullSync);
        }

        $account->update(['last_synced_at' => now()]);

        return $totalNew;
    }

    public function syncNewCommentsForVideo(SocialAccount $account, string $videoId, ?string $accessToken = null, int $commentWindowDays = 7, bool $isFullSync = false): int
    {
        $accessToken = $accessToken ?: $this->validToken($account);

        $storedPost = SocialPost::where('platform', 'youtube')
            ->where('platform_post_id', $videoId)
            ->where('social_account_id', $account->id)
            ->first();

        if (!$storedPost) {
            $videoData = $this->getVideoDetails($videoId, $accessToken);

            $storedPost = SocialPost::create([
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'platform' => 'youtube',
                'platform_post_id' => $videoId,
                'content' => $videoData['title'] ?? '',
                'url' => "https://www.youtube.com/watch?v={$videoId}",
                'posted_at' => now(),
                'metadata' => [
                    'snippet' => $videoData,
                ],
            ]);
        }

        $totalNew = 0;

        // Full sync = no cursor, fetch everything. Normal sync = use per-video cursor.
        $sinceId = $isFullSync
            ? null
            : ($account->metadata['youtube_cursors'][$videoId] ?? null);

        $threads = $this->getCommentThreads($accessToken, $videoId, $sinceId, $commentWindowDays, $isFullSync);

        $newestId = $sinceId;

        foreach ($threads as $thread) {
            $topLevelCommentId = data_get($thread, 'id');
            $totalNew += $this->processCommentThread($account, $storedPost, $thread, $topLevelCommentId, $isFullSync);

            if ($topLevelCommentId && !$isFullSync) {
                // Track the newest ID seen for THIS video
                if ($newestId === null || strcmp($topLevelCommentId, $newestId) > 0) {
                    $newestId = $topLevelCommentId;
                }
            }
        }

        // Save cursor ONCE per video after all threads processed
        if ($newestId && !$isFullSync && $newestId !== $sinceId) {
            $metadata = $account->metadata ?? [];
            $metadata['youtube_cursors'] = $metadata['youtube_cursors'] ?? [];
            $metadata['youtube_cursors'][$videoId] = $newestId;

            $account->update([
                'metadata' => $metadata,
            ]);
        }

        return $totalNew;
    }

    private function processCommentThread(SocialAccount $account, SocialPost $storedPost, array $thread, string $topLevelCommentId, bool $isFullSync = false): int
    {
        $snippet = data_get($thread, 'snippet.topLevelComment.snippet', []);
        $commentId = data_get($thread, 'snippet.topLevelComment.id');

        if (!$commentId) {
            return 0;
        }

        $authorName = data_get($snippet, 'authorDisplayName', 'Unknown');
        $authorAvatar = data_get($snippet, 'authorProfileImageUrl');
        $content = data_get($snippet, 'textDisplay', '');
        $commentedAt = data_get($snippet, 'publishedAt', now()->toIso8601String());

        $parentId = data_get($thread, 'snippet.parentId');

        $parentComment = null;
        if ($parentId) {
            $parentComment = SocialComment::where('platform', 'youtube')
                ->where('platform_comment_id', $parentId)
                ->first();
        }

        $comment = SocialComment::updateOrCreate(
            [
                'platform' => 'youtube',
                'platform_comment_id' => $commentId,
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'social_post_id' => $storedPost->id,
                'platform_parent_id' => $parentId,
                'root_id' => $parentComment?->id,
                'parent_id' => $parentComment?->id,
                'author_name' => $authorName,
                'author_avatar_url' => $authorAvatar,
                'platform_author_id' => data_get($snippet, 'authorChannelId.value'),
                'content' => $content,
                'direction' => 'inbound',
                'status' => 'new',
                'commented_at' => Carbon::parse($commentedAt),
                'metadata' => [
                    'like_count' => data_get($snippet, 'likeCount', 0),
                ],
            ]
        );

        $totalNew = $comment->wasRecentlyCreated ? 1 : 0;

        if ($comment->wasRecentlyCreated && !$isFullSync) {
            AnalyzeWithOllama::dispatch($comment)->onConnection('sync');
        }

        foreach (data_get($thread, 'replies.comments', []) as $reply) {
            $replyId = data_get($reply, 'id');
            if (!$replyId) {
                continue;
            }

            $replySnippet = data_get($reply, 'snippet', []);

            $storedReply = SocialComment::updateOrCreate(
                [
                    'platform' => 'youtube',
                    'platform_comment_id' => $replyId,
                ],
                [
                    'organization_id' => $account->organization_id,
                    'social_account_id' => $account->id,
                    'social_post_id' => $storedPost->id,
                    'platform_parent_id' => $commentId,
                    'root_id' => $comment->id,
                    'parent_id' => $comment->id,
                    'author_name' => data_get($replySnippet, 'authorDisplayName', 'Unknown'),
                    'author_avatar_url' => data_get($replySnippet, 'authorProfileImageUrl'),
                    'platform_author_id' => data_get($replySnippet, 'authorChannelId.value'),
                    'content' => data_get($replySnippet, 'textDisplay', ''),
                    'direction' => 'inbound',
                    'status' => 'new',
                    'commented_at' => Carbon::parse(data_get($replySnippet, 'publishedAt', now()->toIso8601String())),
                    'metadata' => [
                        'like_count' => data_get($replySnippet, 'likeCount', 0),
                    ],
                ]
            );

            if ($storedReply->wasRecentlyCreated) {
                $totalNew++;

                if (!$isFullSync) {
                    AnalyzeWithOllama::dispatch($storedReply)->onConnection('sync');
                }
            }
        }

        return $totalNew;
    }

    public function getVideos(SocialAccount $account, string $accessToken): array
    {
        $cacheKey = "youtube_videos_account_{$account->id}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($accessToken) {
            return $this->fetchVideosFromApi($accessToken);
        });
    }

    private function fetchVideosFromApi(string $accessToken): array
    {
        $channelResponse = Http::withToken($accessToken)->get("{$this->baseUrl}/channels", [
            'part' => 'contentDetails',
            'mine' => 'true',
        ]);

        if (!$channelResponse->successful()) {
            throw new \Exception($channelResponse->json('error.message') ?? 'Unable to fetch YouTube channel.');
        }

        $channel = $channelResponse->json('items.0');
        $uploadsPlaylistId = $channel['contentDetails']['relatedPlaylists']['uploads'] ?? null;

        $videos = [];

        if ($uploadsPlaylistId) {
            $playlistResponse = Http::withToken($accessToken)->get("{$this->baseUrl}/playlistItems", [
                'part' => 'contentDetails,snippet',
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => 50,
            ]);

            if ($playlistResponse->successful()) {
                $videos = $playlistResponse->json('items') ?? [];
            }
        }

        $videoIds = array_filter(array_map(function ($v) {
            return $v['contentDetails']['videoId'] ?? $v['snippet']['resourceId']['videoId'] ?? null;
        }, $videos));

        $statusMap = [];
        if (!empty($videoIds)) {
            $statusResponse = Http::withToken($accessToken)->get("{$this->baseUrl}/videos", [
                'part' => 'status,snippet',
                'id' => implode(',', array_slice($videoIds, 0, 50)),
            ]);

            if ($statusResponse->successful()) {
                foreach ($statusResponse->json('items', []) as $item) {
                    if (!isset($item['status'])) {
                        continue;
                    }
                    $statusMap[$item['id']] = [
                        'privacyStatus' => $item['status']['privacyStatus'] ?? 'public',
                        'commentStatus' => $item['snippet']['commentStatus'] ?? 'allowed',
                        'uploadStatus' => $item['status']['uploadStatus'] ?? 'processed',
                        'embeddable' => $item['status']['embeddable'] ?? true,
                    ];
                }
            }
        }

        foreach ($videos as &$video) {
            $vid = $video['contentDetails']['videoId'] ?? $video['snippet']['resourceId']['videoId'] ?? null;
            $video['id'] = ['videoId' => $vid];
            $video['_status'] = $statusMap[$vid] ?? [
                'privacyStatus' => 'public',
                'commentStatus' => 'allowed',
            ];
        }

        return $videos;
    }

    public function getComments(string $accessToken, string $videoId): array
    {
        $response = Http::withToken($accessToken)->get("{$this->baseUrl}/commentThreads", [
            'part' => 'snippet,replies',
            'videoId' => $videoId,
            'maxResults' => 100,
            'order' => 'time',
            'textFormat' => 'plainText',
        ]);

        $data = $response->json();

        if (!$response->successful()) {
            $reason = $data['error']['errors'][0]['reason'] ?? null;

            if (in_array($reason, ['commentsDisabled', 'videoNotFound'])) {
                return [];
            }

            throw new \Exception($data['error']['message'] ?? 'Unable to fetch YouTube comments.');
        }

        return $data['items'] ?? [];
    }

    public function replyToComment(SocialAccount $account, string $commentId, string $message): array
    {
        $accessToken = $this->validToken($account);

        $response = Http::withToken($accessToken)->post("{$this->baseUrl}/comments?part=snippet", [
            'snippet' => [
                'parentId' => $commentId,
                'textOriginal' => $message,
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception($response->json('error.message') ?? 'Failed to post YouTube reply.');
        }

        $reply = $response->json();

        return [
            'platform_comment_id' => $reply['id'],
            'message' => $message,
            'posted_at' => now()->toIso8601String(),
        ];
    }

    public function subscribeToVideoNotifications(SocialAccount $account): array
    {
        $accessToken = $this->validToken($account);
        $videos = $this->getVideos($account, $accessToken);

        $webhookUrl = rtrim(config('app.url'), '/') . '/webhooks/youtube';
        $hubUrl = 'https://pubsubhubbub.appspot.com/subscribe';

        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($videos as $video) {
            $videoId = $video['id']['videoId']
                ?? $video['snippet']['resourceId']['videoId']
                ?? null;

            if (!$videoId) {
                continue;
            }

            // Pre-filter restricted videos to avoid 403s from the hub
            $status = data_get($video, '_status');
            if ($status) {
                if (($status['privacyStatus'] ?? 'public') !== 'public') {
                    $skipped++;
                    continue;
                }
                if (($status['commentStatus'] ?? 'allowed') === 'disabled') {
                    $skipped++;
                    continue;
                }
                if (($status['uploadStatus'] ?? 'processed') !== 'processed') {
                    $skipped++;
                    continue;
                }
            }

            $topicUrl = "https://www.youtube.com/xml/feeds/videos.xml?video_id={$videoId}";

            $response = Http::asForm()->post($hubUrl, [
                'hub.callback' => $webhookUrl,
                'hub.topic' => $topicUrl,
                'hub.verify' => 'sync',
                'hub.mode' => 'subscribe',
                'hub.lease_seconds' => 864000,
            ]);

            if ($response->successful() || $response->status() === 204) {
                $success++;
            } elseif ($response->status() === 403 || $response->status() === 400) {
                // Hub rejected this specific video — skip it gracefully
                // Causes: age-restricted, kids-content, region-restricted, etc.
                $skipped++;
                Log::info('YouTube PubSubHubbub: video skipped (hub rejected)', [
                    'video_id' => $videoId,
                    'status' => $response->status(),
                ]);
            } else {
                $failed++;
                Log::warning('YouTube PubSubHubbub subscription failed', [
                    'video_id' => $videoId,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
            }
        }

        $metadata = $account->metadata ?? [];
        $videoIds = [];
        foreach ($videos as $video) {
            $vid = $video['id']['videoId'] ?? $video['snippet']['resourceId']['videoId'] ?? null;
            if ($vid) {
                $videoIds[] = $vid;
            }
        }
        $metadata['last_video_ids'] = array_slice(
            array_merge($metadata['last_video_ids'] ?? [], $videoIds),
            -50
        );
        $metadata['pubsub_subscribed'] = $success > 0;
        $metadata['pubsub_subscribed_at'] = now()->toIso8601String();
        $account->update(['metadata' => $metadata]);

        Log::info('YouTube PubSubHubbub subscription completed', [
            'account_id' => $account->id,
            'subscribed' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);

        return ['success' => $success, 'failed' => $failed, 'skipped' => $skipped];
    }

    public function unsubscribeFromVideoNotifications(SocialAccount $account): void
    {
        $videos = $this->getVideos($account, $this->validToken($account));

        $hubUrl = 'https://pubsubhubbub.appspot.com/subscribe';
        $webhookUrl = rtrim(config('app.url'), '/') . '/webhooks/youtube';

        foreach ($videos as $video) {
            $videoId = $video['id']['videoId']
                ?? $video['snippet']['resourceId']['videoId']
                ?? null;

            if (!$videoId) {
                continue;
            }

            $topicUrl = "https://www.youtube.com/xml/feeds/videos.xml?video_id={$videoId}";

            Http::asForm()->post($hubUrl, [
                'hub.callback' => $webhookUrl,
                'hub.topic' => $topicUrl,
                'hub.mode' => 'unsubscribe',
            ]);
        }

        $metadata = $account->metadata ?? [];
        $metadata['pubsub_subscribed'] = false;
        $account->update(['metadata' => $metadata]);
    }

    private function getVideoDetails(string $videoId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get("{$this->baseUrl}/videos", [
            'part' => 'snippet',
            'id' => $videoId,
        ]);

        if (!$response->successful()) {
            return ['title' => "Video {$videoId}"];
        }

        $items = $response->json('items');

        return $items[0]['snippet'] ?? ['title' => "Video {$videoId}"];
    }

    private function getCommentThreads(string $accessToken, string $videoId, ?string $sinceId = null, int $windowDays = 7, bool $isFullSync = false): array
    {
        $cutoff = (!$isFullSync && $windowDays > 0) ? now()->subDays($windowDays) : null;
        $pageToken = null;
        $threads = [];

        do {
            $params = [
                'part' => 'snippet,replies',
                'videoId' => $videoId,
                'maxResults' => 50,
                'order' => 'time',
                'textFormat' => 'plainText',
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::withToken($accessToken)->get("{$this->baseUrl}/commentThreads", $params);

            if (!$response->successful()) {
                $reason = $response->json('error.errors.0.reason');

                if (in_array($reason, ['commentsDisabled', 'videoNotFound'])) {
                    return [];
                }

                Log::warning('YouTube commentThreads fetch failed', [
                    'video_id' => $videoId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $items = $response->json('items') ?? [];

            foreach ($items as $thread) {
                $publishedAt = data_get($thread, 'snippet.topLevelComment.snippet.publishedAt');

                if ($cutoff && $publishedAt && Carbon::parse($publishedAt)->lt($cutoff)) {
                    continue;
                }

                $threadId = data_get($thread, 'id');

                // Stop at sinceId cursor — already processed this one
                if ($sinceId && $threadId === $sinceId) {
                    return $threads;
                }

                $threads[] = $thread;
            }

            $pageToken = $response->json('nextPageToken');
        } while ($pageToken);

        return $threads;
    }

    private function validToken(SocialAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->isFuture()) {
            return $account->access_token;
        }

        if (!$account->refresh_token) {
            throw new \Exception('YouTube access token expired and no refresh token available.');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => env('YOUTUBE_CLIENT_ID'),
            'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to refresh YouTube token.');
        }

        $tokenData = $response->json();

        $account->update([
            'access_token' => $tokenData['access_token'],
            'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
        ]);

        return $tokenData['access_token'];
    }

    private function shouldAnalyzeComment(SocialAccount $account, SocialComment $comment): bool
    {
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

        // Reply to ALL comments since auto_reply was turned on
        return $comment->commented_at->gte($account->auto_reply_started_at);
    }

    public function publishReply(SocialComment $comment, string $message, SocialAccount $account): array
    {
        $accessToken = $this->validToken($account);

        // YouTube replies go to the parent comment thread
        $parentId = $comment->platform_parent_id ?: $comment->platform_comment_id;

        $response = Http::withToken($accessToken)->post(
            "{$this->baseUrl}/comments?part=snippet",
            [
                'snippet' => [
                    'parentId' => $parentId,
                    'textOriginal' => $message,
                ],
            ]
        );

        if (!$response->successful()) {
            Log::error('YouTube reply publish failed', [
                'comment_id' => $comment->id,
                'parent_id' => $parentId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \Exception(
                $response->json('error.message') ?? 'YouTube reply failed'
            );
        }

        $replyData = $response->json('snippet', []);

        return [
            'id' => $response->json('id'),
            'url' => null,
        ];
    }
}
