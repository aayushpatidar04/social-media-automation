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
            $account = $this->comment->socialAccount;

            // Only reply to comments since auto_reply_started_at
            if (!$account || !$account->auto_reply_started_at) {
                return;
            }

            if (!$this->comment->commented_at || $this->comment->commented_at->lt($account->auto_reply_started_at)) {
                return;
            }

            if ($this->comment->is_own_comment) {
                return;
            }

            $openAiService = new OpenAIService();

            $responseData = $openAiService->generateResponse(
                $this->comment,
                $this->analysis['intent']
            );

            $this->comment->aiConversation()->update([
                'ai_response' => $responseData['response'],
                'confidence_score' => $responseData['confidence'],
                'requires_human_review' => $responseData['requires_review'],
                'review_reason' => $responseData['review_reason'],
                'response_status' => $responseData['requires_review'] ? 'pending' : 'approved',
            ]);

            if (!$responseData['requires_review'] && $responseData['response']) {
                PublishAutoReply::dispatch($this->comment)->onConnection('sync');
            }
        } catch (\Exception $e) {
            Log::error('Response Generation Error: ' . $e->getMessage());
        }
    }
}
