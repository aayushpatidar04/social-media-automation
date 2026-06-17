<?php

namespace App\Services;

use App\Jobs\AnalyzeWithOllama;
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
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => env('YOUTUBE_CLIENT_ID'),
            'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => env('YOUTUBE_REDIRECT_URI'),
        ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['error_description'] ?? $data['error'] ?? 'YouTube token exchange failed.');
        }

        return $data;
    }

    public function refreshAccessToken(SocialAccount $account): string
    {
        if (!$account->refresh_token) {
            throw new \Exception('YouTube refresh token missing. Please reconnect YouTube.');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => env('YOUTUBE_CLIENT_ID'),
            'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['error_description'] ?? $data['error'] ?? 'YouTube token refresh failed.');
        }

        $account->update([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $data['access_token'];
    }

    public function validToken(SocialAccount $account): string
    {
        if (!$account->token_expires_at || now()->greaterThan($account->token_expires_at->subMinutes(5))) {
            return $this->refreshAccessToken($account);
        }

        return $account->access_token;
    }

    public function getMyChannel(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)->get("{$this->baseUrl}/channels", [
            'part' => 'snippet,contentDetails,statistics',
            'mine' => 'true',
        ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['error']['message'] ?? 'Unable to fetch YouTube channel.');
        }

        return $data['items'][0] ?? null;
    }

    public function syncComments(SocialAccount $account): int
    {
        $accessToken = $this->validToken($account);

        $totalComments = 0;

        $videos = $this->getVideos($account, $accessToken);

        Log::info('YouTube videos found: ' . count($videos));

        foreach ($videos as $video) {
            $videoId = $video['id']['videoId']
                ?? $video['snippet']['resourceId']['videoId']
                ?? null;

            if (!$videoId) {
                continue;
            }

            $storedPost = SocialPost::updateOrCreate(
                [
                    'platform_post_id' => $videoId,
                    'platform' => 'youtube',
                ],
                [
                    'organization_id' => $account->organization_id,
                    'social_account_id' => $account->id,
                    'content' => $video['snippet']['title'] ?? '',
                    'posted_at' => $video['snippet']['publishedAt'] ?? now(),
                    'raw_payload' => $video,
                ]
            );

            $threads = $this->getComments($accessToken, $videoId);

            foreach ($threads as $thread) {
                $topLevelComment = data_get($thread, 'snippet.topLevelComment');

                if (!$topLevelComment) {
                    continue;
                }

                $storedRootComment = $this->storeYouTubeComment(
                    account: $account,
                    storedPost: $storedPost,
                    comment: $topLevelComment,
                    parentComment: null,
                    isOwnComment: false
                );

                if ($storedRootComment?->wasRecentlyCreated) {
                    $totalComments++;
                }

                $replies = data_get($thread, 'replies.comments', []);

                foreach ($replies as $reply) {
                    $storedReply = $this->storeYouTubeComment(
                        account: $account,
                        storedPost: $storedPost,
                        comment: $reply,
                        parentComment: $storedRootComment,
                        isOwnComment: $this->isOwnYouTubeComment($account, $reply)
                    );

                    if ($storedReply?->wasRecentlyCreated) {
                        $totalComments++;
                    }
                }
            }
        }

        $account->update([
            'last_synced_at' => now(),
        ]);

        return $totalComments;
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

    public function replyToComment(SocialAccount $account, string $parentCommentId, string $message): array
    {
        $accessToken = $this->validToken($account);

        $response = Http::withToken($accessToken)->post("{$this->baseUrl}/comments?part=snippet", [
            'snippet' => [
                'parentId' => $parentCommentId,
                'textOriginal' => $message,
            ],
        ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['error']['message'] ?? 'Unable to reply to YouTube comment.');
        }

        return $data;
    }

    public function publishReply(SocialComment $comment, string $message, SocialAccount $account): array
    {
        try {
            $data = $this->replyToComment(
                $account,
                $comment->platform_comment_id,
                $message
            );

            $comment->update([
                'status' => 'replied',
                'ai_response_text' => $message,
                'replied_at' => now(),
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error('YouTube publish reply exception', [
                'comment_id' => $comment->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function storeYouTubeComment(
        SocialAccount $account,
        SocialPost $storedPost,
        array $comment,
        ?SocialComment $parentComment = null,
        bool $isOwnComment = false
    ): ?SocialComment {
        $commentId = $comment['id'] ?? null;
        $snippet = $comment['snippet'] ?? [];

        if (!$commentId) {
            return null;
        }

        $authorId = data_get($snippet, 'authorChannelId.value')
            ?? $snippet['authorDisplayName']
            ?? null;

        if (!$authorId) {
            Log::warning('YouTube comment missing author id', [
                'comment_id' => $commentId,
                'comment' => $comment,
            ]);

            return null;
        }

        $platformParentId = $snippet['parentId'] ?? null;

        $rootId = null;
        $platformRootId = $commentId;

        if ($parentComment) {
            $rootId = $parentComment->root_id ?: $parentComment->id;
            $platformRootId = $parentComment->platform_root_id ?: $parentComment->platform_comment_id;
        }

        $storedComment = SocialComment::updateOrCreate(
            [
                'platform_comment_id' => $commentId,
                'platform' => 'youtube',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'social_post_id' => $storedPost->id,

                'parent_id' => $parentComment?->id,
                'root_id' => $rootId,

                'platform_parent_id' => $platformParentId,
                'platform_root_id' => $platformRootId,

                'author_name' => $snippet['authorDisplayName'] ?? 'Unknown',
                'platform_author_id' => $authorId,
                'content' => $snippet['textOriginal'] ?? $snippet['textDisplay'] ?? '',

                'direction' => $isOwnComment ? 'outbound' : 'inbound',
                'sender_type' => $isOwnComment ? 'page' : 'customer',
                'is_own_comment' => $isOwnComment,

                'raw_payload' => $comment,

                'commented_at' => isset($snippet['publishedAt'])
                    ? \Carbon\Carbon::parse($snippet['publishedAt'])->setTimezone('Asia/Kolkata')
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
                SocialComment::where('id', $parentComment->root_id)
                    ->increment('reply_count');
            }
        }

        if ($storedComment->wasRecentlyCreated) {
            if ($this->shouldAnalyzeComment($account, $storedComment)) {
                AnalyzeWithOllama::dispatch($storedComment);
            }
        }

        return $storedComment;
    }

    private function isOwnYouTubeComment(SocialAccount $account, array $comment): bool
    {
        $authorChannelId = data_get($comment, 'snippet.authorChannelId.value');

        if (!$authorChannelId || !$account->platform_account_id) {
            return false;
        }

        return (string) $authorChannelId === (string) $account->platform_account_id;
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