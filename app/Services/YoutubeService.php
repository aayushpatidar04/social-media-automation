<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
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

    public function syncComments(SocialAccount $account): int
    {
        $accessToken = $this->validToken($account);
        $videos = $this->getVideos($account, $accessToken);

        $totalNew = 0;

        foreach ($videos as $video) {
            $videoId = $video['id']['videoId']
                ?? $video['snippet']['resourceId']['videoId']
                ?? null;

            if (!$videoId) {
                continue;
            }

            $totalNew += $this->syncNewCommentsForVideo($account, $videoId, $accessToken);
        }

        $account->update(['last_synced_at' => now()]);

        return $totalNew;
    }

    public function syncNewCommentsForVideo(SocialAccount $account, string $videoId, ?string $accessToken = null): int
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
        $sinceId = $account->metadata['last_synced_comment_id'] ?? null;

        $threads = $this->getCommentThreads($accessToken, $videoId, $sinceId);

        foreach ($threads as $thread) {
            $topLevelCommentId = data_get($thread, 'id');
            $totalNew += $this->processCommentThread($account, $storedPost, $thread, $topLevelCommentId);

            if ($topLevelCommentId) {
                $account->update([
                    'metadata' => array_merge($account->metadata ?? [], [
                        'last_synced_comment_id' => $topLevelCommentId,
                    ]),
                ]);
            }
        }

        return $totalNew;
    }

    private function processCommentThread(SocialAccount $account, SocialPost $storedPost, array $thread, string $topLevelCommentId): int
    {
        $snippet = data_get($thread, 'snippet.topLevelComment.snippet', []);
        $commentId = data_get($thread, 'snippet.topLevelComment.id');

        if (!$commentId) {
            return 0;
        }

        $exists = SocialComment::where('platform', 'youtube')
            ->where('platform_comment_id', $commentId)
            ->exists();

        if ($exists) {
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

        $rootId = $parentComment?->id;

        SocialComment::create([
            'organization_id' => $account->organization_id,
            'social_account_id' => $account->id,
            'social_post_id' => $storedPost->id,
            'platform' => 'youtube',
            'platform_comment_id' => $commentId,
            'platform_parent_id' => $parentId,
            'root_id' => $rootId,
            'parent_id' => $parentComment?->id,
            'author_name' => $authorName,
            'author_avatar_url' => $authorAvatar,
            'platform_author_id' => data_get($snippet, 'authorChannelId.value'),
            'content' => $content,
            'direction' => 'inbound',
            'status' => 'new',
            'commented_at' => $commentedAt,
            'metadata' => [
                'like_count' => data_get($snippet, 'likeCount', 0),
            ],
        ]);

        return 1;
    }

    public function getVideos(SocialAccount $account, string $accessToken): array
    {
        // Cache videos for 12 hours — avoids redundant API calls on every sync
        $cacheKey = "youtube_videos_account_{$account->id}";

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($accessToken) {
            return $this->fetchVideosFromApi($accessToken);
        });
    }

    private function fetchVideosFromApi(string $accessToken): array
    {

        // Use channels.list to get the uploads playlist — costs only 1 quota unit
        // instead of 100 for /search, which avoids daily quota exhaustion
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
            // Get the 25 most recent uploads via the uploads playlist
            $playlistResponse = Http::withToken($accessToken)->get("{$this->baseUrl}/playlistItems", [
                'part' => 'contentDetails,snippet',
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => 25,
            ]);

            if ($playlistResponse->successful()) {
                $videos = $playlistResponse->json('items') ?? [];
            }
        }

        // Enrich each video with privacy + comment status from /videos endpoint
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

        // Reshape so callers can read videoId the same way as before
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

        $response = Http::withToken($accessToken)->post("{$this->baseUrl}/comments", [
            'part' => 'snippet',
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

    /**
     * Subscribe to PubSubHubbub for real-time comment notifications.
     * Skips private/unlisted/deleted videos with comments disabled.
     */
    public function subscribeToVideoNotifications(SocialAccount $account): array
    {
        $accessToken = $this->validToken($account);
        $videos = $this->getVideos($account, $accessToken);

        $webhookUrl = rtrim(config('app.url'), '/') . '/webhooks/youtube';
        $hubUrl = 'https://pubsubhubbub.appspot.com/subscribe';

        // Resolve channel ID — prefer account metadata, fall back to any video's snippet
        $channelId = $account->metadata['youtube_channel_id'] ?? null;

        if (!$channelId) {
            foreach ($videos as $video) {
                $channelId = $video['snippet']['channelId'] ?? null;
                if ($channelId) {
                    break;
                }
            }
        }

        if (!$channelId) {
            Log::warning('YouTube PubSubHubbub: no channel ID found, cannot subscribe', [
                'account_id' => $account->id,
            ]);
            return ['success' => 0, 'failed' => 1, 'skipped' => 0];
        }

        // Per-video status filtering no longer happens here — YouTube rejects
        // per-video topics. Filter by video ID in the webhook handler instead.
        $videoIds = [];
        foreach ($videos as $video) {
            $videoId = $video['id']['videoId']
                ?? $video['snippet']['resourceId']['videoId']
                ?? null;
            if ($videoId) {
                $videoIds[] = $videoId;
            }
        }

        $success = 0;
        $failed = 0;
        $skipped = 0;

        // Channel-level topic is the only topic third parties may subscribe to
        $topicUrl = "https://www.youtube.com/xml/feeds/videos.xml?channel_id={$channelId}";

        $response = Http::asForm()->post($hubUrl, [
            'hub.callback'   => $webhookUrl,
            'hub.topic'      => $topicUrl,
            'hub.verify'     => 'sync',
            'hub.mode'       => 'subscribe',
            'hub.lease_seconds' => 864000,
        ]);

        if ($response->successful() || $response->status() === 204) {
            $success = 1;
        } else {
            $failed = 1;
            Log::warning('YouTube PubSubHubbub channel subscription failed', [
                'account_id' => $account->id,
                'channel_id' => $channelId,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
        }

        $metadata = $account->metadata ?? [];
        $metadata['youtube_channel_id'] = $channelId;
        $metadata['last_video_ids'] = array_slice(
            array_merge($metadata['last_video_ids'] ?? [], $videoIds),
            -50
        );
        $metadata['pubsub_subscribed'] = $success === 1;
        $metadata['pubsub_subscribed_at'] = now()->toIso8601String();
        $account->update(['metadata' => $metadata]);

        Log::info('YouTube PubSubHubbub subscription completed', [
            'account_id' => $account->id,
            'channel_id' => $channelId,
            'subscribed' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'videos_tracked' => count($videoIds),
        ]);

        return ['success' => $success, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Unsubscribe from video notifications.
     */
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

    private function getCommentThreads(string $accessToken, string $videoId, ?string $sinceId = null): array
    {
        $response = Http::withToken($accessToken)->get("{$this->baseUrl}/commentThreads", [
            'part' => 'snippet,replies',
            'videoId' => $videoId,
            'maxResults' => 100,
            'order' => 'time',
            'textFormat' => 'plainText',
        ]);

        if (!$response->successful()) {
            $reason = $response->json('error.errors.0.reason');

            if (in_array($reason, ['commentsDisabled', 'videoNotFound'])) {
                return [];
            }

            return [];
        }

        $threads = $response->json('items') ?? [];

        if ($sinceId) {
            $filtered = [];
            $foundSince = false;

            foreach ($threads as $thread) {
                $threadId = data_get($thread, 'id');

                if ($threadId === $sinceId) {
                    $foundSince = true;
                    break;
                }

                $filtered[] = $thread;
            }

            if ($foundSince) {
                return $filtered;
            }
        }

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

    private function isOwnYouTubeComment(SocialAccount $account, array $comment): bool
    {
        $channelId = data_get($account->metadata, 'channel.snippet.channelId');

        if (!$channelId) {
            return false;
        }

        $commentAuthorChannelId = data_get($comment, 'snippet.authorChannelId.value');

        return $commentAuthorChannelId === $channelId;
    }
}
