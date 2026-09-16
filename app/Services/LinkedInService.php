<?php

namespace App\Services;

use App\Jobs\AnalyzeWithOllama;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinkedInService
{
    private string $baseUrl = 'https://api.linkedin.com/v2';

    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => env('LINKEDIN_REDIRECT_URI'),
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        ]);

        if (!$response->successful()) {
            throw new \Exception('LinkedIn token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public function getMyProfile(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders([
                'LinkedIn-Version' => '202405',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->get("{$this->baseUrl}/userinfo");

        if (!$response->successful()) {
            return null;
        }

        return $response->json();
    }

    public function syncComments(SocialAccount $account, array $options = []): int
    {
        $commentWindowDays = $options['comment_window_days'] ?? 7;
        $isFullSync = $options['full_sync'] ?? false;
        $accessToken = $this->validToken($account);

        // NO post age filter — fetch comments from ALL posts
        $commentCutoff = ($isFullSync || $commentWindowDays === 0)
            ? Carbon::createFromTimestamp(0)
            : now()->subDays($commentWindowDays);

        $posts = $this->getOrganizationPosts($account, $accessToken);

        $totalComments = 0;

        foreach ($posts as $post) {
            $postId = $post['id'] ?? $post['urn'] ?? null;

            if (!$postId) {
                continue;
            }

            // Store post (no age filter)
            $publishedAt = data_get($post, 'createdAt') ?? data_get($post, 'publishedAt');
            $storedPost = SocialPost::updateOrCreate(
                [
                    'platform_post_id' => $postId,
                    'platform' => 'linkedin',
                ],
                [
                    'organization_id' => $account->organization_id,
                    'social_account_id' => $account->id,
                    'content' => $post['commentary'] ?? $post['text'] ?? '',
                    'posted_at' => $publishedAt ? date('Y-m-d H:i:s', intval($publishedAt / 1000)) : now(),
                ]
            );

            $comments = $this->getPostComments($account, $accessToken, $postId);

            foreach ($comments as $comment) {
                $commentId = $comment['id'] ?? null;

                if (!$commentId) {
                    continue;
                }

                // Skip only comments older than the comment window
                $commentedAt = data_get($comment, 'createdAt') ?? data_get($comment, 'publishedAt');
                if ($commentedAt && Carbon::parse($commentedAt)->lt($commentCutoff)) {
                    continue;
                }

                $message = $comment['message']['text'] ?? $comment['text'] ?? '';

                $storedComment = SocialComment::updateOrCreate(
                    [
                        'platform_comment_id' => $commentId,
                        'platform' => 'linkedin',
                    ],
                    [
                        'organization_id' => $account->organization_id,
                        'social_account_id' => $account->id,
                        'social_post_id' => $storedPost->id,
                        'author_name' => data_get($comment, 'author.name', 'LinkedIn User'),
                        'platform_author_id' => data_get($comment, 'author.id'),
                        'content' => $message,
                        'commented_at' => $commentedAt ? date('Y-m-d H:i:s', intval($commentedAt / 1000)) : now(),
                        'status' => 'new',
                    ]
                );

                if ($storedComment->wasRecentlyCreated) {
                    $totalComments++;

                    // Only dispatch AI in normal sync
                    if (!$isFullSync && $this->shouldAnalyzeComment($account, $storedComment)) {
                        AnalyzeWithOllama::dispatch($storedComment);
                    }
                }
            }
        }

        $account->update(['last_synced_at' => now()]);

        return $totalComments;
    }

    public function replyToComment(SocialAccount $account, string $commentId, string $message): array
    {
        $accessToken = $this->validToken($account);

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'LinkedIn-Version' => '202405',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->post("{$this->baseUrl}/socialActions/{$commentId}/comments", [
                'message' => ['text' => $message],
            ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['message'] ?? 'Failed to reply on LinkedIn.');
        }

        return $data;
    }

    public function syncSingleCommentFromWebhook(SocialAccount $account, array $value): ?SocialComment
    {
        $commentId = $value['comment_id'] ?? $value['id'] ?? null;
        $postId = $value['post_id'] ?? $value['object'] ?? null;

        if (!$commentId || !$postId) {
            return null;
        }

        $storedPost = SocialPost::firstOrCreate(
            [
                'platform_post_id' => $postId,
                'platform' => 'linkedin',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'content' => '',
                'posted_at' => now(),
            ]
        );

        $author = data_get($value, 'author', []);
        $authorName = data_get($author, 'name', 'LinkedIn User');
        $authorId = data_get($author, 'id');

        $storedComment = SocialComment::updateOrCreate(
            [
                'platform_comment_id' => $commentId,
                'platform' => 'linkedin',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'social_post_id' => $storedPost->id,
                'author_name' => $authorName,
                'platform_author_id' => $authorId,
                'content' => data_get($value, 'message.text', ''),
                'direction' => 'inbound',
                'status' => 'new',
                'commented_at' => now(),
            ]
        );

        if ($storedComment?->wasRecentlyCreated) {
            if ($this->shouldAnalyzeComment($account, $storedComment)) {
                AnalyzeWithOllama::dispatch($storedComment);
            }
        }

        return $storedComment;
    }

    private function getOrganizationPosts(SocialAccount $account, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders([
                'LinkedIn-Version' => '202405',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->get("{$this->baseUrl}/ugcPosts", [
                'q' => 'authors',
                'authors' => 'List(urn:li:organization:' . $account->platform_account_id . ')',
                'count' => 50,
                'sortBy' => 'LAST_MODIFIED',
            ]);

        if (!$response->successful()) {
            throw new \Exception($response->json('message') ?? 'Failed to fetch LinkedIn posts.');
        }

        return $response->json('elements') ?? [];
    }

    private function getPostComments(SocialAccount $account, string $accessToken, string $postId): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders([
                'LinkedIn-Version' => '202405',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->get("{$this->baseUrl}/socialActions/{$postId}/comments", [
                'count' => 100,
            ]);

        if (!$response->successful()) {
            Log::error('LinkedIn comments error', ['response' => $response->json()]);
            return [];
        }

        return $response->json('elements') ?? [];
    }

    private function validToken(SocialAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->isFuture()) {
            return $account->access_token;
        }

        if (!$account->refresh_token) {
            throw new \Exception('LinkedIn access token expired and no refresh token.');
        }

        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to refresh LinkedIn token.');
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

        return $comment->commented_at->gte(
            $account->auto_reply_started_at
        );
    }
}
