<?php

// app/Jobs/SyncFacebookComments.php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\InstagramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncInstagramComments implements ShouldQueue
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

    /**
     * Execute the job.
     */
    public function handle()
    {
        $service = new InstagramService();

        $count = $service->syncComments(
            $this->account
        );

        Log::info(
            "Instagram comments synced: {$count}"
        );
    }
}
