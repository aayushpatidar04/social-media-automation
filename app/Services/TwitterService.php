<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
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

    public function getAuthUrl(string $state, string $codeChallenge): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => env('TWITTER_CLIENT_ID'),
            'redirect_uri' => env('TWITTER_REDIRECT_URI'),
            'scope' => 'tweet.read tweet.write users.read offline.access',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return "https://x.com/i/oauth2/authorize?{$params}";
    }

    public function exchangeCodeForToken(string $code, string $codeVerifier): array
    {
        $response = Http::asForm()
            ->withBasicAuth(env('TWITTER_CLIENT_ID'), env('TWITTER_CLIENT_SECRET'))
            ->post('https://api.x.com/2/oauth2/token', [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => env('TWITTER_CLIENT_ID'),
                'redirect_uri' => env('TWITTER_REDIRECT_URI'),
                'code_verifier' => $codeVerifier,
            ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['error_description'] ?? $data['error'] ?? 'X token exchange failed.');
        }

        return $data;
    }

    public function refreshAccessToken(SocialAccount $account): string
    {
        if (!$account->refresh_token) {
            throw new \Exception('X refresh token missing. Please reconnect X.');
        }

        $response = Http::asForm()
            ->withBasicAuth(env('TWITTER_CLIENT_ID'), env('TWITTER_CLIENT_SECRET'))
            ->post('https://api.x.com/2/oauth2/token', [
                'refresh_token' => $account->refresh_token,
                'grant_type' => 'refresh_token',
                'client_id' => env('TWITTER_CLIENT_ID'),
            ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['error_description'] ?? $data['error'] ?? 'X token refresh failed.');
        }

        $account->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 7200),
        ]);

        return $data['access_token'];
    }

    public function validToken(SocialAccount $account): string
    {
        if (!$account->token_expires_at || now()->greaterThan($account->token_expires_at->copy()->subMinutes(5))) {
            return $this->refreshAccessToken($account);
        }

        return $account->access_token;
    }

    public function getCurrentUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get("{$this->baseUrl}/users/me", [
            'user.fields' => 'id,name,username,profile_image_url',
        ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['detail'] ?? $data['title'] ?? 'Unable to fetch X user.');
        }

        return $data['data'];
    }

    public function syncComments(SocialAccount $account): int
    {
        $accessToken = $this->validToken($account);

        $tweets = $this->getMentions($account, $accessToken);

        Log::info('X mentions found', [
            'count' => count($tweets),
        ]);

        $total = 0;

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

            $total++;

            \App\Jobs\AnalyzeWithOllama::dispatch($storedComment);
        }

        $account->update(['last_synced_at' => now()]);

        return $total;
    }

    public function getMentions(SocialAccount $account, string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get(
            "{$this->baseUrl}/users/{$account->platform_account_id}/mentions",
            [
                'max_results' => 100,
                'tweet.fields' => 'id,text,author_id,created_at,conversation_id,referenced_tweets',
                'expansions' => 'author_id',
                'user.fields' => 'id,name,username',
            ]
        );

        $data = $response->json();

        Log::info('X mentions response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            throw new \Exception($data['detail'] ?? $data['title'] ?? 'Unable to fetch X mentions.');
        }

        $users = collect($data['includes']['users'] ?? [])->keyBy('id');

        return collect($data['data'] ?? [])->map(function ($tweet) use ($users) {
            $author = $users->get($tweet['author_id'] ?? '');

            return [
                ...$tweet,
                'author_name' => $author['name'] ?? null,
                'author_username' => $author['username'] ?? null,
            ];
        })->values()->toArray();
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

        if (!$response->successful()) {
            throw new \Exception($data['detail'] ?? $data['title'] ?? 'Unable to reply on X.');
        }

        return $data;
    }
}