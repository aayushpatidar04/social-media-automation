<?php

namespace App\Jobs;
 
use App\Models\SocialComment;
use App\Services\OpenAIService;
use App\Services\AnalyticsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
 
class AnalyzeCommentWithAI implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 
    public $tries = 2;
    public $timeout = 30;
 
    private SocialComment $comment;
 
    public function __construct(SocialComment $comment)
    {
        $this->comment = $comment;
    }
 
    public function handle(): void
    {
        try {
            $openAiService = new OpenAIService();
            
            // Analyze comment with AI
            $analysis = $openAiService->analyzeComment($this->comment);
 
            // Update comment with analysis
            $this->comment->update([
                'sentiment' => $analysis['sentiment'],
                'sentiment_score' => $analysis['sentiment_score'],
                'intent' => $analysis['intent'],
                'lead_score' => $analysis['lead_score'],
                'is_lead' => $analysis['is_lead'],
            ]);
 
            // Create AI conversation record
            $this->comment->aiConversation()->create([
                'original_comment' => $this->comment->content,
                'ai_analysis' => $analysis,
                'social_account_id' => $this->comment->social_account_id,
                'confidence_score' => $analysis['confidence'],
            ]);
 
            // Generate response if it's a sales/support inquiry
            if (in_array($analysis['intent'], ['sales', 'support', 'question'])) {
                GenerateAIResponse::dispatch($this->comment, $analysis);
            }
 
            // Log activity
            \App\Models\ActivityLog::create([
                'organization_id' => $this->comment->socialAccount->organization_id,
                'action' => 'comment_analyzed',
                'entity_type' => 'social_comment',
                'entity_id' => $this->comment->id,
                'changes' => $analysis,
            ]);
 
            // Broadcast to real-time dashboard
            $analytics = new AnalyticsService($this->comment->socialAccount->organization);
            $analytics->broadcastCommentReceived($this->comment);
            $analytics->updateMetric('sentiment');
            $analytics->updateMetric('intent');
        } catch (\Exception $e) {
            Log::error("AI Analysis Error for comment {$this->comment->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}