<?php

// app/Services/FacebookService.php - COMPLETE VERSION

namespace App\Services;

use App\Jobs\AnalyzeCommentWithAI;
use App\Jobs\AnalyzeWithOllama;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class InstagramService
{
    protected string $graphVersion = 'v25.0';

    public function syncComments(SocialAccount $account): int
    {
        $totalComments = 0;

        $mediaList = $this->getMedia($account);

        foreach ($mediaList as $media) {

            $storedPost = SocialPost::updateOrCreate(
                [
                    'platform_post_id' => $media['id'],
                    'platform' => 'instagram',
                ],
                [
                    'organization_id' => $account->organization_id,
                    'social_account_id' => $account->id,
                    'content' => $media['caption'] ?? '',
                    'posted_at' => $media['timestamp'] ?? now(),
                ]
            );

            $comments = $this->getMediaComments(
                $account,
                $media['id']
            );

            foreach ($comments as $comment) {

                $storedComment = SocialComment::updateOrCreate(
                    [
                        'platform_comment_id' => $comment['id'],
                        'platform' => 'instagram',
                    ],
                    [
                        'organization_id' => $account->organization_id,
                        'social_account_id' => $account->id,
                        'social_post_id' => $storedPost->id,
                        'author_name' => $comment['username'] ?? 'Unknown',
                        'platform_author_id' => $comment['username'] ?? null,
                        'content' => $comment['text'] ?? '',
                        'commented_at' => $comment['timestamp'] ?? now(),
                        'status' => 'new',
                    ]
                );

                $totalComments++;

                // AnalyzeCommentWithAI::dispatch($storedComment);
                AnalyzeWithOllama::dispatch($storedComment);
            }
        }

        return $totalComments;
    }

    private function getConnectedInstagramAccount(
        SocialAccount $account
    ): ?string {
        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$account->platform_account_id}",
            [
                'fields' => 'connected_instagram_account',
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        Log::info('Instagram Account Lookup', $data);

        if (!$response->successful()) {
            throw new \Exception(
                $data['error']['message'] ?? 'Unable to fetch Instagram account'
            );
        }

        return $data['connected_instagram_account']['id'] ?? null;
    }

    private function getMedia(SocialAccount $account): array
    {
        $instagramId = $this->getConnectedInstagramAccount($account);

        if (!$instagramId) {
            Log::warning(
                'No Instagram account connected to page ' .
                $account->platform_account_name
            );

            return [];
        }

        Log::info("Instagram ID: {$instagramId}");

        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$instagramId}/media",
            [
                'fields' => 'id,caption,media_type,timestamp',
                'limit' => 100,
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        Log::info('Instagram Media Response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            throw new \Exception(
                $data['error']['message'] ?? 'Unable to fetch media'
            );
        }

        return $data['data'] ?? [];
    }

    private function getMediaComments(SocialAccount $account, string $mediaId): array
    {
        $response = Http::get(
            "https://graph.facebook.com/{$this->graphVersion}/{$mediaId}/comments",
            [
                'fields' =>
                    'id,text,username,timestamp',
                'limit' => 100,
                'access_token' => $account->access_token,
            ]
        );

        $data = $response->json();

        if (!$response->successful()) {
            Log::error($data);

            return [];
        }

        return $data['data'] ?? [];
    }

    /**
     * Publish a reply to an Instagram comment
     */
    public function publishReply(SocialComment $comment, string $message, SocialAccount $account): bool
    {
        try {
            $response = Http::post(
                "https://graph.facebook.com/{$this->graphVersion}/{$comment->platform_comment_id}/replies",
                [
                    'message' => $message,
                    'access_token' => $account->access_token,
                ]
            );

            $data = $response->json();

            if (!$response->successful() || isset($data['error'])) {
                Log::error('Instagram publish reply failed', $data);
                return false;
            }

            $comment->update([
                'status' => 'replied',
                'ai_response_text' => $message,
                'replied_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Instagram publish reply exception: ' . $e->getMessage());
            return false;
        }
    }
}