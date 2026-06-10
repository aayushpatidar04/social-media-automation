<?php

// app/Jobs/AnalyzeCommentWithAI.php

namespace App\Jobs;

use App\Models\SocialComment;
use App\Services\OpenAIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeCommentWithAI implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 2;

    private SocialComment $comment;

    public function __construct(SocialComment $comment)
    {
        $this->comment = $comment;
    }

    public function handle()
    {
        try {
            Log::info('Analyzing comment: ' . $this->comment->id);

            $service = new OpenAIService();

            // Analyze sentiment, intent, and lead score
            $analysis = $service->analyzeComment($this->comment);

            Log::info('Analysis complete for comment: ' . $this->comment->id, $analysis);

            // Update comment with analysis results
            $this->comment->update([
                'sentiment' => $analysis['sentiment'],
                'sentiment_score' => $analysis['sentiment_score'],
                'intent' => $analysis['intent'],
                'intent_confidence' => $analysis['confidence'],
                'lead_score' => $analysis['lead_score'],
                'is_lead' => $analysis['is_lead'],
                'ai_analysis_completed_at' => now(),
            ]);

            Log::info('Comment updated with analysis: ' . $this->comment->id);

            // If it's a potential lead or high-value comment, generate AI response
            if ($analysis['is_lead'] || $analysis['intent'] === 'sales') {
                GenerateAIResponse::dispatch($this->comment);
            }

            // Broadcast update to dashboard
            \Illuminate\Support\Facades\Broadcast::channel('analytics.org.' . $this->comment->organization_id)
                ->notify(new \App\Notifications\CommentAnalyzed($this->comment));

        } catch (\Exception $e) {
            Log::error('AI analysis failed for comment: ' . $this->comment->id . ' - ' . $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Exception $exception)
    {
        Log::error('AI analysis job permanently failed for comment: ' . $this->comment->id, [
            'error' => $exception->getMessage(),
        ]);

        $this->comment->update([
            'ai_analysis_failed' => true,
            'ai_error_message' => $exception->getMessage(),
        ]);
    }
}