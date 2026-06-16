<?php

namespace App\Jobs;
 
use App\Models\SocialComment;
use App\Services\FacebookService;
use App\Services\InstagramService;
use App\Services\TwitterService;
use App\Services\YoutubeService;
use App\Services\LinkedInService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
 
class PublishAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 
    public $tries = 3;
    public $backoff = [60, 120, 300];
 
    private SocialComment $comment;
 
    public function __construct(SocialComment $comment)
    {
        $this->comment = $comment;
    }
 
    public function handle(): void
    {
        try {
            $aiConversation = $this->comment->aiConversation;
            if (!$aiConversation || !$aiConversation->ai_response) {
                return;
            }
 
            $service = null;

            if ($this->comment->platform === 'facebook') {
                $service = new FacebookService();
            } elseif ($this->comment->platform === 'instagram') {
                $service = new InstagramService();
            } elseif ($this->comment->platform === 'twitter') {
                $service = new TwitterService();
            } elseif ($this->comment->platform === 'youtube') {
                $service = new YoutubeService();
            } elseif ($this->comment->platform === 'linkedin') {
                $service = new LinkedInService();
            }

            if (! $service) {
                Log::warning('Auto reply not supported for platform: ' . $this->comment->platform);
                return;
            }

            $success = $service->publishReply(
                $this->comment,
                $aiConversation->ai_response,
                $this->comment->socialAccount
            );

            if ($success) {
                $aiConversation->update([
                    'response_status' => 'auto_sent',
                    'sent_at' => now(),
                    'send_status' => 'success',
                ]);
 
                $this->comment->update(['status' => 'replied']);
 
                Log::info("Auto reply sent for comment {$this->comment->id}");
            } else {
                throw new \Exception('Failed to publish reply');
            }
        } catch (\Exception $e) {
            Log::error("Publish Reply Error: " . $e->getMessage());
            
            // Update error status
            if ($this->comment->aiConversation()) {
                $this->comment->aiConversation()->update([
                    'send_status' => 'failed',
                    'send_error_message' => $e->getMessage(),
                ]);
            }
 
            $this->fail($e);
        }
    }
}