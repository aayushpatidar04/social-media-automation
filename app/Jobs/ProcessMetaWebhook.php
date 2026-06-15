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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessMetaWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(private array $payload)
    {
    }

    public function handle()
    {
        Log::info('Processing Meta webhook', $this->payload);

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

        if (($value['item'] ?? null) !== 'comment') {
            return;
        }

        if (($value['verb'] ?? null) !== 'add') {
            return;
        }

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

        app(FacebookService::class)->syncSingleCommentFromWebhook($account, $value);
    }

    private function handleInstagramComment(array $entry, array $change): void
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

        app(InstagramService::class)->syncSingleCommentFromWebhook($account, $commentId);
    }

    private function findFacebookAccountByInstagramId(string $instagramAccountId): ?SocialAccount
    {
        $facebookAccounts = SocialAccount::where('platform', 'facebook')
            ->where('is_active', true)
            ->get();

        foreach ($facebookAccounts as $account) {
            $pageId = $account->platform_account_id;
            $pageToken = $account->access_token;

            $response = Http::get("https://graph.facebook.com/v18.0/{$pageId}", [
                'fields' => 'connected_instagram_account',
                'access_token' => $pageToken,
            ]);

            if (!$response->successful()) {
                continue;
            }

            $connectedInstagramId = data_get($response->json(), 'connected_instagram_account.id');

            if ($connectedInstagramId == $instagramAccountId) {
                return $account;
            }
        }

        return null;
    }
}