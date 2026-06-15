<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\SocialAccount;
use App\Jobs\SyncSocialCommentsJob;

#[Signature('sync:social-cron')]
#[Description('Command description')]
class SyncSocialCron extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        SocialAccount::where('status', 'active')
            ->whereIn('platform', ['youtube', 'twitter', 'linkedin'])
            ->chunk(50, function ($accounts) {
                foreach ($accounts as $account) {
                    SyncSocialCommentsJob::dispatch($account->id)
                        ->onQueue('social-sync');
                }
            });

        return self::SUCCESS;
    }
}
