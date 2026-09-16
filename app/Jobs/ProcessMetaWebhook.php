<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Services\FacebookService;
use App\Services\InstagramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMetaWebhook implements ShouldQueue
{
    use CascadeDeleteComments, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(private array $payload)
    {
    }

    public function handle()
    {

        foreach ($this->payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? null;

                if ($field === 'feed') {
                    $this->handleFacebookFeed($entry, $change);
                }

                if ($field === 'comments') {
                    $this->handleInstagramComment($entry, $change);
                }
            }
        }
    }

    private function handleFacebookFeed(array $entry, array $change): void
    {
        $value = $change['value'] ?? [];

        $verb = $value['verb'] ?? null;

        if ($verb === 'add') {
            $this->handleFacebookFeedAdd($entry, $value);
        } elseif ($verb === 'remove') {
            $this->handleFacebookFeedRemove($entry, $value);
        }
    }

    private function handleFacebookFeedAdd(array $entry, array $value): void
    {
        $pageId = $entry['id'] ?? null;

        if (!$pageId) {
            return;
        }

        $account = SocialAccount::where('platform_account_id', $pageId)
            ->where('platform', 'facebook')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            Log::warning('Facebook webhook account not found', [
                'page_id' => $pageId,
            ]);
            return;
        }

        $item = $value['item'] ?? null;

        if ($item === 'comment') {
            app(FacebookService::class)->syncSingleCommentFromWebhook($account, $value);
            return;
        }

        if (in_array($item, ['status', 'photo', 'video', 'post', 'share'], true)) {
            app(FacebookService::class)->syncSinglePostFromWebhook($account, $value);
            return;
        }

    }

    private function handleFacebookFeedRemove(array $entry, array $value): void
    {
        $item = $value['item'] ?? null;
        $pageId = $entry['id'] ?? null;

        if (!$pageId) {
            return;
        }

        $account = SocialAccount::where('platform_account_id', $pageId)
            ->where('platform', 'facebook')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            Log::warning('Facebook webhook account not found for remove', [
                'page_id' => $pageId,
            ]);
            return;
        }

        if ($item === 'comment') {
            $commentId = $value['comment_id'] ?? null;

            if ($commentId) {
                $this->cascadeDeleteComment('facebook', $commentId);
            }
        }

        if (in_array($item, ['status', 'photo', 'video', 'post', 'share'], true)) {
            $postId = $value['post_id'] ?? $value['id'] ?? null;

            if ($postId) {
                $this->cascadeDeletePost('facebook', $postId);
            }
        }
    }

    private function handleInstagramComment(array $entry, array $change): void
    {
        $value = $change['value'] ?? [];

        $verb = $value['verb'] ?? null;

        if ($verb === 'add') {
            $this->handleInstagramCommentAdd($entry, $change);
        } elseif ($verb === 'remove') {
            $this->handleInstagramCommentRemove($entry, $change);
        }
    }

    private function handleInstagramCommentAdd(array $entry, array $change): void
    {
        $value = $change['value'] ?? [];

        $commentId = $value['id'] ?? null;

        if (!$commentId) {
            return;
        }

        $instagramAccountId = $entry['id'] ?? null;

        $account = $this->findFacebookAccountByInstagramId($instagramAccountId);

        if (!$account) {
            Log::warning('Instagram webhook account not found', [
                'instagram_account_id' => $instagramAccountId,
                'comment_id' => $commentId,
            ]);
            return;
        }

        // Fetch full comment data from Instagram Graph API
        $instagramService = app(InstagramService::class);
        $fullCommentData = $instagramService->fetchCommentData($account, $commentId);

        if (!$fullCommentData) {
            Log::warning('Could not fetch Instagram comment data', [
                'comment_id' => $commentId,
            ]);
            return;
        }

        // Build the value array that InstagramService.syncSingleCommentFromWebhook expects
        $instagramValue = [
            'comment_id' => $fullCommentData['id'] ?? $commentId,
            'post_id' => $fullCommentData['media_id'] ?? null,
            'parent_id' => $fullCommentData['parent_id'] ?? null,
            'text' => $fullCommentData['text'] ?? '',
            'from' => $fullCommentData['from'] ?? [],
            'timestamp' => $fullCommentData['timestamp'] ?? null,
            'media' => $fullCommentData['media'] ?? [],
        ];

        app(InstagramService::class)->syncSingleCommentFromWebhook($account, $instagramValue);
    }

    private function handleInstagramCommentRemove(array $entry, array $change): void
    {
        $value = $change['value'] ?? [];

        $commentId = $value['id'] ?? null;

        if (!$commentId) {
            return;
        }

        $this->cascadeDeleteComment('instagram', $commentId);
    }

    private function findFacebookAccountByInstagramId(?string $instagramAccountId): ?SocialAccount
    {
        if (!$instagramAccountId) {
            return null;
        }

        $facebookAccounts = SocialAccount::where('platform', 'facebook')
            ->where('is_active', true)
            ->get();

        foreach ($facebookAccounts as $account) {
            $pageId = $account->platform_account_id;
            $pageToken = $account->access_token;

            $response = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/{$pageId}", [
                'fields' => 'connected_instagram_account',
                'access_token' => $pageToken,
            ]);

            if (!$response->successful()) {
                continue;
            }

            $connectedInstagramId = data_get($response->json(), 'connected_instagram_account.id');

            if ((string) $connectedInstagramId === (string) $instagramAccountId) {
                return $account;
            }
        }

        return null;
    }
}
