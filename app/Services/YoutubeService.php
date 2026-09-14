<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $rootId = $parentId ?: $commentId;

        $parentComment = null;
        if ($parentId) {
            $parentComment = SocialComment::where('platform', 'youtube')
                ->where('platform_comment_id', $parentId)
                ->first();
        }

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
        $response = Http::withToken($accessToken)->get("{$this->baseUrl}/search", [
            'part' => 'snippet',
            'forMine' => 'true',
            'type' => 'video',
            'maxResults' => 25,
            'order' => 'date',
        ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['error']['message'] ?? 'Unable to fetch YouTube videos.');
        }

        return $data['items'] ?? [];
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

        // Pre-fetch privacy + comment status to skip restricted videos
        $videoIds = array_filter(array_map(function ($v) {
            return $v['id']['videoId'] ?? $v['snippet']['resourceId']['videoId'] ?? null;
        }, $videos));

        $videoStatusMap = [];
        if (!empty($videoIds)) {
            // Use videos.list instead of search.list so we get status + snippet in one call
            $statusResponse = Http::withToken($accessToken)->get("{$this->baseUrl}/videos", [
                'part' => 'status,snippet,contentDetails',
                'id' => implode(',', array_slice($videoIds, 0, 50)),
            ]);
            if ($statusResponse->successful()) {
                foreach ($statusResponse->json('items', []) as $item) {
                    // If status part is missing, this is a restricted video — skip it
                    if (!isset($item['status'])) {
                        continue;
                    }
                    $videoStatusMap[$item['id']] = [
                        'privacyStatus' => $item['status']['privacyStatus'] ?? 'public',
                        'commentStatus' => $item['snippet']['commentStatus'] ?? 'allowed',
                        'uploadStatus' => $item['status']['uploadStatus'] ?? 'processed',
                        'embeddable' => $item['status']['embeddable'] ?? true,
                    ];
                }
            }
        }

        $webhookUrl = rtrim(config('app.url'), '/') . '/webhooks/youtube';
        $hubUrl = 'https://pubsubhubbub.appspot.com/subscribe';

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $subscribedVideoIds = [];

        foreach ($videos as $video) {
            $videoId = $video['id']['videoId']
                ?? $video['snippet']['resourceId']['videoId']
                ?? null;

            if (!$videoId) {
                continue;
            }

            $vStatus = $videoStatusMap[$videoId] ?? null;

            // If status is missing entirely, skip — it's a restricted/age-gated/kids video
            if (!$vStatus) {
                Log::info('YouTube PubSubHubbub: skipping video with unavailable status', [
                    'video_id' => $videoId,
                ]);
                $skipped++;
                continue;
            }

            if (($vStatus['privacyStatus'] ?? 'public') !== 'public') {
                $skipped++;
                continue;
            }
            if (($vStatus['commentStatus'] ?? 'allowed') === 'disabled') {
                $skipped++;
                continue;
            }
            if (($vStatus['uploadStatus'] ?? 'processed') !== 'processed') {
                $skipped++;
                continue;
            }
            if (($vStatus['embeddable'] ?? 'true') === 'false') {
                $skipped++;
                continue;
            }

            $subscribedVideoIds[] = $videoId;

            $topicUrl = "https://www.youtube.com/xml/feeds/videos.xml?video_id={$videoId}";

            $response = Http::asForm()->post($hubUrl, [
                'hub.callback' => $webhookUrl,
                'hub.topic' => $topicUrl,
                'hub.verify' => 'sync',
                'hub.mode' => 'subscribe',
                'hub.lease_seconds' => 864000,
            ]);

            if ($response->successful()) {
                $success++;
            } elseif ($response->status() === 403 && str_contains($response->body(), 'Restricted')) {
                // Hub itself rejected this video — count as skipped, not failed
                $skipped++;
                Log::info('YouTube PubSubHubbub: hub rejected video (restricted)', [
                    'video_id' => $videoId,
                ]);
            } else {
                $failed++;
                Log::warning('YouTube PubSubHubbub subscription failed', [
                    'video_id' => $videoId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        }

        $metadata = $account->metadata ?? [];
        $metadata['last_video_ids'] = array_slice(
            array_merge($metadata['last_video_ids'] ?? [], $subscribedVideoIds),
            -50
        );
        $metadata['pubsub_subscribed'] = true;
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
