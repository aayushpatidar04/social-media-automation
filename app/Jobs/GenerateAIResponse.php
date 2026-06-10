<?php

namespace App\Jobs;
 
use App\Models\SocialComment;
use App\Services\OpenAIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
 
class GenerateAIResponse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 
    public $tries = 2;
    public $timeout = 30;
 
    private SocialComment $comment;
    private array $analysis;
 
    public function __construct(SocialComment $comment, array $analysis)
    {
        $this->comment = $comment;
        $this->analysis = $analysis;
    }
 
    public function handle(): void
    {
        try {
            $openAiService = new OpenAIService();
 
            // Generate response
            $responseData = $openAiService->generateResponse(
                $this->comment,
                $this->analysis['intent']
            );
 
            // Update AI conversation
            $this->comment->aiConversation()->update([
                'ai_response' => $responseData['response'],
                'confidence_score' => $responseData['confidence'],
                'requires_human_review' => $responseData['requires_review'],
                'review_reason' => $responseData['review_reason'],
                'response_status' => $responseData['requires_review'] ? 'pending' : 'approved',
            ]);
 
            // Send for auto-reply or queue for manual review
            if (!$responseData['requires_review'] && $responseData['response']) {
                PublishAutoReply::dispatch($this->comment);
            }
        } catch (\Exception $e) {
            Log::error("Response Generation Error: " . $e->getMessage());
            // Don't fail job, just log the error
        }
    }
}