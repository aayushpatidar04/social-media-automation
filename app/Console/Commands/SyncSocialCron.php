<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\SocialAccount;
use App\Jobs\SyncSocialCommentsJob;

class SyncSocialCron extends Command
{
    protected $signature = 'sync:social-cron';
    protected $description = 'Syncing comments for all connected platforms';

    public function handle()
    {
        SocialAccount::where('status', 'connected')
            ->whereIn('platform', ['youtube', 'twitter', 'linkedin', 'facebook', 'instagram'])
            ->chunk(50, function ($accounts) {
                foreach ($accounts as $account) {
                    // Normal incremental sync: 7-day comment window, no full sync
                    SyncSocialCommentsJob::dispatch($account->id, [
                        'post_window_days' => 0, // 0 = don't skip posts by age
                        'comment_window_days' => 7,
                        'full_sync' => false,
                    ], false);
                }
            });

        return self::SUCCESS;
    }
}
