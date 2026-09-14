<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\FacebookService;
use App\Services\InstagramService;
use App\Services\LinkedInService;
use App\Services\TwitterService;
use App\Services\YoutubeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SyncSocialCommentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public int $accountId)
    {
    }

    public function handle(
        YoutubeService $youTubeService,
        TwitterService $twitterService,
        LinkedInService $linkedInService,
        FacebookService $facebookService,
        InstagramService $instagramService
    ): void {
        $account = SocialAccount::find($this->accountId);

        if (!$account || $account->status !== 'connected') {
            return;
        }

        // Configurable windows — default to last 30 days for posts, 7 days for comments
        $postDays = config('sync.post_window_days', 30);
        $commentDays = config('sync.comment_window_days', 7);

        $options = [
            'post_window_days' => $postDays,
            'comment_window_days' => $commentDays,
            'sync_mode' => 'incremental', // 'incremental' or 'full'
        ];

        Log::info('Sync started', [
            'account_id' => $account->id,
            'platform' => $account->platform,
            'post_window_days' => $postDays,
            'comment_window_days' => $commentDays,
        ]);

        try {
            $newComments = match ($account->platform) {
                'youtube' => $youTubeService->syncComments($account, $options),
                'twitter' => $twitterService->syncComments($account, $options),
                'linkedin' => $linkedInService->syncComments($account, $options),
                'facebook' => $facebookService->syncPageComments($account, $options),
                'instagram' => $instagramService->syncComments($account, $options),
                default => 0,
            };

            $account->update(['last_synced_at' => now()]);

            Log::info('Sync completed', [
                'account_id' => $account->id,
                'platform' => $account->platform,
                'new_comments' => $newComments,
            ]);

        } catch (\Exception $e) {
            Log::error('Sync failed', [
                'account_id' => $account->id,
                'platform' => $account->platform,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}