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
            $videoId = $video['id']['videoId'] ?? $video['snippet']['resourceId']['videoId'] ?? null;

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
                ]
            );

            $comments = $this->getComments($accessToken, $videoId);

            foreach ($comments as $thread) {
                $comment = $thread['snippet']['topLevelComment'] ?? null;

                if (!$comment) {
                    continue;
                }

                $snippet = $comment['snippet'] ?? [];

                $storedComment = SocialComment::updateOrCreate(
                    [
                        'platform_comment_id' => $comment['id'],
                        'platform' => 'youtube',
                    ],
                    [
                        'organization_id' => $account->organization_id,
                        'social_account_id' => $account->id,
                        'social_post_id' => $storedPost->id,
                        'author_name' => $snippet['authorDisplayName'] ?? 'Unknown',
                        'platform_author_id' => $snippet['authorChannelId']['value'] ?? null,
                        'content' => $snippet['textOriginal'] ?? $snippet['textDisplay'] ?? '',
                        'commented_at' => $snippet['publishedAt'] ?? now(),
                        'status' => 'new',
                    ]
                );

                if ($storedComment->wasRecentlyCreated) {
                    $totalComments++;
                    \App\Jobs\AnalyzeWithOllama::dispatch($storedComment);
                }

            }
        }

        $account->update(['last_synced_at' => now()]);

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
            'part' => 'snippet',
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

    public function publishReply(SocialComment $comment, string $message, SocialAccount $account): bool
    {
        try {
            $this->replyToComment($account, $comment->platform_comment_id, $message);

            $comment->update([
                'status' => 'replied',
                'ai_response_text' => $message,
                'replied_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('YouTube publish reply exception: ' . $e->getMessage());
            return false;
        }
    }
}