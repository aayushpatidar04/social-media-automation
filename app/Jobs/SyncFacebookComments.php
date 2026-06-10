<?php

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
 
    public $tries = 3;
    public $backoff = [60, 120, 300];
 
    private SocialAccount $account;
 
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
    }
 
    public function handle(): void
    {
        try {
            $service = new FacebookService($this->account);
 
            // Sync Facebook posts and comments
            $facebookPostCount = $service->syncFacebookPosts();
            Log::info("Synced {$facebookPostCount} Facebook posts for account {$this->account->id}");
 
            // Also sync Instagram if connected
            $instagramCommentCount = $service->syncInstagramComments();
            Log::info("Synced {$instagramCommentCount} Instagram comments for account {$this->account->id}");
 
            // Update last synced time
            $this->account->update(['last_synced_at' => now()]);
 
            // Dispatch analysis jobs for new comments
            $this->dispatchAnalysisJobs();
        } catch (\Exception $e) {
            Log::error("Facebook Sync Error: " . $e->getMessage());
            $this->fail($e);
        }
    }
 
    private function dispatchAnalysisJobs(): void
    {
        $newComments = $this->account->socialComments()
            ->where('sentiment', 'pending')
            ->limit(50)
            ->get();
 
        foreach ($newComments as $comment) {
            AnalyzeCommentWithAI::dispatch($comment);
        }
    }
}