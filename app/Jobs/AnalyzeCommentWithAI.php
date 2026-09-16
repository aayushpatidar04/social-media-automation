<?php

namespace App\Jobs;

use App\Models\SocialComment;
use App\Models\Lead;
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

    public function __construct(public SocialComment $comment)
    {
    }

    public function handle()
    {
        try {
            $service = new OpenAIService();

            $analysis = $service->analyzeComment($this->comment);

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

            // Create Lead record if AI detected it as a lead or sales intent
            if ($analysis['is_lead'] || $analysis['intent'] === 'sales') {
                $this->createOrUpdateLead($analysis);
            }

            // Generate AI response for high-value comments
            if ($analysis['is_lead'] || $analysis['intent'] === 'sales') {
                GenerateAIResponse::dispatch($this->comment);
            }

        } catch (\Exception $e) {
            Log::error('AI analysis failed for comment: ' . $this->comment->id . ' - ' . $e->getMessage());

            $this->comment->update([
                'ai_analysis_failed' => true,
                'ai_error_message' => $e->getMessage(),
                'ai_analysis_completed_at' => now(),
            ]);
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

    private function createOrUpdateLead(array $analysis): void
    {
        $existingLead = Lead::where('social_comment_id', $this->comment->id)->first();

        if ($existingLead) {
            $existingLead->update([
                'lead_score' => $analysis['lead_score'],
            ]);
            return;
        }

        $leadType = match ($analysis['intent'] ?? 'sales') {
            'sales' => 'sales',
            'support' => 'support',
            default => 'sales',
        };

        Lead::create([
            'organization_id' => $this->comment->socialAccount->organization_id,
            'social_comment_id' => $this->comment->id,
            'platform_author_id' => $this->comment->author_id,
            'author_name' => $this->comment->author_name,
            'author_profile_url' => $this->comment->author_avatar_url,
            'initial_message' => substr($this->comment->content, 0, 500),
            'lead_type' => $leadType,
            'lead_score' => $analysis['lead_score'],
            'lead_status' => 'new',
        ]);
    }
}
