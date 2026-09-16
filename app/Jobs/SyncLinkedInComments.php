<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\LinkedInService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncLinkedInComments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(private SocialAccount $account, public array $options = [], public bool $fullSync = false)
    {
    }

    public function handle(LinkedInService $linkedin): void
    {

        $count = $linkedin->syncComments($this->account, $this->options);

        \App\Models\ActivityLog::create([
            'organization_id' => $this->account->organization_id,
            'user_id' => $this->account->user_id,
            'action' => 'linkedin_sync_completed',
            'entity_type' => 'social_account',
            'entity_id' => $this->account->id,
            'data' => ['comments_synced' => $count, 'full_sync' => $this->fullSync],
        ]);
    }
}
