<?php

// app/Jobs/SyncFacebookComments.php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\FacebookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncFacebookComments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;
    public $maxExceptions = 3;

    private SocialAccount $account;

    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
    }

    public function handle()
    {
        try {
            Log::info('Starting sync job for account: ' . $this->account->platform_account_name);

            $service = new FacebookService();
            $commentCount = $service->syncPageComments($this->account);

            Log::info('Sync job completed. Comments synced: ' . $commentCount);

            // Broadcast update
            \App\Models\ActivityLog::create([
                'organization_id' => $this->account->organization_id,
                'user_id' => $this->account->user_id,
                'action' => 'sync_completed',
                'entity_type' => 'social_account',
                'entity_id' => $this->account->id,
                'data' => ['comments_synced' => $commentCount],
            ]);

        } catch (\Exception $e) {
            Log::error('Sync job failed: ' . $e->getMessage(), [
                'account_id' => $this->account->id,
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    public function failed(\Exception $exception)
    {
        Log::error('Sync job permanently failed for account: ' . $this->account->id, [
            'error' => $exception->getMessage(),
        ]);

        $this->account->update([
            'status' => 'error',
            'error_message' => $exception->getMessage(),
        ]);
    }
}