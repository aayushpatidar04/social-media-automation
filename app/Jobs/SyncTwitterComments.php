<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\TwitterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTwitterComments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(private SocialAccount $account)
    {
    }

    public function handle(TwitterService $twitter): void
    {
        Log::info('Starting X sync for account: ' . $this->account->platform_account_name);

        $count = $twitter->syncComments($this->account);

        Log::info('X sync completed. Mentions synced: ' . $count);

        \App\Models\ActivityLog::create([
            'organization_id' => $this->account->organization_id,
            'user_id' => $this->account->user_id,
            'action' => 'twitter_sync_completed',
            'entity_type' => 'social_account',
            'entity_id' => $this->account->id,
            'data' => ['comments_synced' => $count],
        ]);
    }
}