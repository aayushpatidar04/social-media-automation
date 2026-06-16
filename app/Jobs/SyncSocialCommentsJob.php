<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\LinkedInService;
use App\Services\TwitterService;
use App\Services\YoutubeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSocialCommentsJob implements ShouldQueue
{
    use FoundationQueueable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public int $accountId
    ) {}

    public function handle(
        YoutubeService $youTubeService,
        TwitterService $twitterService,
        LinkedInService $linkedInService
    ): void {
        $account = SocialAccount::find($this->accountId);

        if (!$account || $account->status !== 'connected') {
            return;
        }

        match ($account->platform) {
            'youtube' => $youTubeService->syncComments($account),
            'twitter' => $twitterService->syncComments($account),
            'linkedin' => $linkedInService->syncComments($account),
            default => null,
        };
    }
}