<?php

namespace App\Jobs;
 
use App\Models\SocialComment;
use App\Services\FacebookService;
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
 
            $service = new FacebookService($this->comment->socialAccount);
            $success = $service->publishReply(
                $this->comment,
                $aiConversation->ai_response
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
            $this->comment->aiConversation()->update([
                'send_status' => 'failed',
                'send_error_message' => $e->getMessage(),
            ]);
 
            $this->fail($e);
        }
    }
}