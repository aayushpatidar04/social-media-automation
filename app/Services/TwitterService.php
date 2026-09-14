<?php

namespace App\Services;

use App\Jobs\AnalyzeWithOllama;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwitterService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('TWITTER_API_BASE', 'https://api.x.com/2');
    }

    public function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    public function generateCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function exchangeCodeForToken(string $code): array
    {
        $clientId = env('TWITTER_CLIENT_ID');
        $clientSecret = env('TWITTER_CLIENT_SECRET');
        $redirectUri = env('TWITTER_REDIRECT_URI');

        $response = Http::asForm()->post('https://api.x.com/2/oauth2/token', [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Twitter token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public function getMyProfile(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)->get("{$this->baseUrl}/users/me", [
            'user.fields' => 'id,name,username,description,public_metrics',
        ]);

        if (!$response->successful()) {
            return null;
        }

        return $response->json('data');
    }

    public function syncComments(SocialAccount $account, array $options = []): int
    {
        $accessToken = $this->validToken($account);
        $isFullSync = $options['full_sync'] ?? false;

        // Use since_id for incremental sync (only new mentions since last run)
        $sinceId = $account->metadata['last_synced_tweet_id'] ?? null;

        $params = [
            'max_results' => 100,
            'tweet.fields' => 'id,text,author_id,created_at,conversation_id,referenced_tweets',
            'expansions' => 'author_id',
            'user.fields' => 'id,name,username',
        ];

        // In full sync mode, don't use since_id — fetch all
        if (!$isFullSync && $sinceId) {
            $params['since_id'] = $sinceId;
        }

        $response = Http::withToken($accessToken)->get(
            "{$this->baseUrl}/users/{$account->platform_account_id}/mentions",
            $params
        );

        $data = $response->json();

        $windowDays = $options['comment_window_days'] ?? 7;

        // In full sync mode or window = 0, don't filter by date
        if (!$isFullSync && $windowDays > 0) {
            $cutoff = now()->subDays($windowDays);

            // Filter tweets by date — only the tweets array, not the whole response
            $data['data'] = array_filter($data['data'] ?? [], function ($tweet) use ($cutoff) {
                return Carbon::parse($tweet['created_at'])->gte($cutoff);
            });
        }

        Log::info('X mentions response', [
            'status' => $response->status(),
            'since_id' => $sinceId,
            'count' => count($data['data'] ?? []),
            'full_sync' => $isFullSync,
        ]);

        if (!$response->successful()) {
            throw new \Exception($data['detail'] ?? $data['title'] ?? 'Unable to fetch X mentions.');
        }

        $users = collect($data['includes']['users'] ?? [])->keyBy('id');

        $tweets = collect($data['data'] ?? [])->map(function ($tweet) use ($users) {
            $author = $users->get($tweet['author_id'] ?? '');

            return [
                ...$tweet,
                'author_name' => $author['name'] ?? null,
                'author_username' => $author['username'] ?? null,
            ];
        })->values()->toArray();

        $total = 0;
        $maxTweetId = $sinceId;

        foreach ($tweets as $tweet) {
            $storedPost = SocialPost::updateOrCreate(
                [
                    'platform_post_id' => $tweet['id'],
                    'platform' => 'twitter',
                ],
                [
                    'organization_id' => $account->organization_id,
                    'social_account_id' => $account->id,
                    'content' => $tweet['text'] ?? '',
                    'posted_at' => $tweet['created_at'] ?? now(),
                ]
            );

            $storedComment = SocialComment::updateOrCreate(
                [
                    'platform_comment_id' => $tweet['id'],
                    'platform' => 'twitter',
                ],
                [
                    'organization_id' => $account->organization_id,
                    'social_account_id' => $account->id,
                    'social_post_id' => $storedPost->id,
                    'author_name' => $tweet['author_name'] ?? 'X User',
                    'platform_author_id' => $tweet['author_id'] ?? null,
                    'content' => $tweet['text'] ?? '',
                    'commented_at' => $tweet['created_at'] ?? now(),
                    'status' => 'new',
                ]
            );

            if ($storedComment->wasRecentlyCreated) {
                $total++;

                // Only dispatch AI in normal sync
                if (!$isFullSync && $this->shouldAnalyzeComment($account, $storedComment)) {
                    AnalyzeWithOllama::dispatch($storedComment);
                }
            }

            if (!$maxTweetId || intval($tweet['id']) > intval($maxTweetId)) {
                $maxTweetId = $tweet['id'];
            }
        }

        // Save cursor for next sync
        if ($maxTweetId && !$isFullSync) {
            $metadata = $account->metadata ?? [];
            $metadata['last_synced_tweet_id'] = $maxTweetId;
            $account->update(['metadata' => $metadata]);
        }

        $account->update(['last_synced_at' => now()]);

        Log::info('X sync completed', [
            'account_id' => $account->id,
            'new_comments' => $total,
            'full_sync' => $isFullSync,
        ]);

        return $total;
    }

    public function replyToTweet(SocialAccount $account, string $tweetId, string $message): array
    {
        $accessToken = $this->validToken($account);

        $response = Http::withToken($accessToken)->post("{$this->baseUrl}/tweets", [
            'text' => $message,
            'reply' => [
                'in_reply_to_tweet_id' => $tweetId,
            ],
        ]);

        $data = $response->json();

        Log::info('X reply response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            throw new \Exception($data['detail'] ?? $data['title'] ?? 'Unable to reply on X.');
        }

        return $data;
    }

    private function validToken(SocialAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->isFuture()) {
            return $account->access_token;
        }

        if (!$account->refresh_token) {
            throw new \Exception('Twitter access token expired and no refresh token.');
        }

        $response = Http::asForm()->post('https://api.x.com/2/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
            'client_id' => env('TWITTER_CLIENT_ID'),
            'client_secret' => env('TWITTER_CLIENT_SECRET'),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to refresh X token.');
        }

        $tokenData = $response->json();

        $account->update([
            'access_token' => $tokenData['access_token'],
            'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 7200),
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

        return $comment->commented_at->gte($account->auto_reply_started_at);
    }
}
