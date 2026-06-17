<?php

namespace App\Services;

use App\Jobs\AnalyzeWithOllama;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinkedInService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('LINKEDIN_API_BASE', 'https://api.linkedin.com');
    }

    public function getAuthUrl(string $state): string
    {
        $scope = implode(' ', [
            'openid',
            'profile',
            'email',

            // These may become available after LinkedIn product approval.
            // If OAuth fails due to invalid scope, remove these until approval.
            'w_member_social',
            'r_organization_social',
            'w_organization_social',
        ]);

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'redirect_uri' => env('LINKEDIN_REDIRECT_URI'),
            'state' => $state,
            'scope' => $scope,
        ]);

        return "https://www.linkedin.com/oauth/v2/authorization?{$params}";
    }

    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => env('LINKEDIN_REDIRECT_URI'),
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        ]);

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['error_description'] ?? $data['error'] ?? 'LinkedIn token exchange failed.');
        }

        return $data;
    }

    public function validToken(SocialAccount $account): string
    {
        if (!$account->token_expires_at || now()->greaterThan($account->token_expires_at->copy()->subMinutes(5))) {
            throw new \Exception('LinkedIn token expired. Please reconnect LinkedIn.');
        }

        return $account->access_token;
    }

    public function getCurrentUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get("{$this->baseUrl}/v2/userinfo");

        $data = $response->json();

        if (!$response->successful()) {
            throw new \Exception($data['message'] ?? 'Unable to fetch LinkedIn user profile.');
        }

        return $data;
    }

    public function getManagedOrganizations(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders([
                'LinkedIn-Version' => '202405',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->get("{$this->baseUrl}/v2/organizationalEntityAcls", [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'projection' => '(elements*(organizationalTarget~(id,localizedName,vanityName)))',
            ]);

        $data = $response->json();

        Log::info('LinkedIn organizations response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            throw new \Exception($data['message'] ?? 'Unable to fetch LinkedIn organizations. Product approval may be pending.');
        }

        return collect($data['elements'] ?? [])
            ->map(function ($item) {
                $org = $item['organizationalTarget~'] ?? null;

                if (!$org) {
                    return null;
                }

                return [
                    'id' => 'urn:li:organization:' . $org['id'],
                    'numeric_id' => $org['id'],
                    'name' => $org['localizedName'] ?? 'LinkedIn Organization',
                    'vanityName' => $org['vanityName'] ?? null,
                    'raw' => $org,
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    public function syncComments(SocialAccount $account): int
    {
        $accessToken = $this->validToken($account);

        $posts = $this->getOrganizationPosts($account, $accessToken);

        Log::info('LinkedIn posts found', [
            'count' => count($posts),
        ]);

        $totalComments = 0;

        foreach ($posts as $post) {
            $postId = $post['id'] ?? $post['urn'] ?? null;

            if (!$postId) {
                continue;
            }

            $storedPost = SocialPost::updateOrCreate(
                [
                    'platform_post_id' => $postId,
                    'platform' => 'linkedin',
                ],
                [
                    'organization_id' => $account->organization_id,
                    'social_account_id' => $account->id,
                    'content' => $post['commentary'] ?? $post['text'] ?? '',
                    'posted_at' => isset($post['createdAt']) ? date('Y-m-d H:i:s', intval($post['createdAt'] / 1000)) : now(),
                ]
            );

            $comments = $this->getPostComments($account, $accessToken, $postId);

            foreach ($comments as $comment) {
                $commentId = $comment['id'] ?? null;

                if (!$commentId) {
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
                        'author_name' => $comment['authorName'] ?? 'LinkedIn User',
                        'platform_author_id' => $comment['actor'] ?? null,
                        'content' => $message,
                        'commented_at' => isset($comment['createdAt'])
                            ? \Carbon\Carbon::createFromTimestampMs($comment['createdAt'])
                                ->setTimezone('Asia/Kolkata')
                                ->format('Y-m-d H:i:s')
                            : now()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),

                        'status' => 'new',
                    ]
                );

                if ($storedComment->wasRecentlyCreated) {
                    $totalComments++;

                    if ($this->shouldAnalyzeComment($account, $storedComment)) {
                        AnalyzeWithOllama::dispatch($storedComment);
                    }
                }

            }
        }

        $account->update(['last_synced_at' => now()]);

        return $totalComments;
    }

    public function getOrganizationPosts(SocialAccount $account, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders([
                'LinkedIn-Version' => '202405',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->get("{$this->baseUrl}/rest/posts", [
                'q' => 'author',
                'author' => $account->platform_account_id,
                'count' => 50,
                'sortBy' => 'LAST_MODIFIED',
            ]);

        $data = $response->json();

        Log::info('LinkedIn posts response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            throw new \Exception($data['message'] ?? 'Unable to fetch LinkedIn posts. Product approval may be pending.');
        }

        return $data['elements'] ?? [];
    }

    public function getPostComments(SocialAccount $account, string $accessToken, string $postUrn): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders([
                'LinkedIn-Version' => '202405',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->get("{$this->baseUrl}/rest/socialActions/{$postUrn}/comments", [
                'count' => 100,
            ]);

        $data = $response->json();

        Log::info('LinkedIn comments response', [
            'post_urn' => $postUrn,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            throw new \Exception($data['message'] ?? 'Unable to fetch LinkedIn comments. Community Management API approval may be pending.');
        }

        return $data['elements'] ?? [];
    }

    public function replyToComment(SocialAccount $account, string $parentCommentUrn, string $message): array
    {
        $accessToken = $this->validToken($account);

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'LinkedIn-Version' => '202405',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->post("{$this->baseUrl}/rest/socialActions/{$parentCommentUrn}/comments", [
                'actor' => $account->platform_account_id,
                'message' => [
                    'text' => $message,
                ],
            ]);

        $data = $response->json();

        Log::info('LinkedIn reply response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            throw new \Exception($data['message'] ?? 'Unable to reply on LinkedIn.');
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
            Log::error('LinkedIn publish reply exception: ' . $e->getMessage());
            return false;
        }
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