<?php

// app/Jobs/AnalyzeWithOllama.php - AI Analysis using Ollama

namespace App\Jobs;

use App\Models\SocialComment;
use App\Models\Lead;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeWithOllama implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(public SocialComment $comment)
    {
    }

    public function handle()
    {
        try {
            $service = new OllamaService();

            $analysisText = $this->comment->content;
            if (strlen($analysisText) > 1000) {
                $analysisText = substr($analysisText, 0, 1000);
            }

            // Run analysis calls
            $sentimentAnalysis = $service->analyzeSentiment($analysisText);
            $intentAnalysis = $service->classifyIntent($analysisText);
            $leadAnalysis = $service->detectLead($analysisText);

            $analysis = [
                'sentiment' => $sentimentAnalysis['sentiment'],
                'sentiment_score' => $sentimentAnalysis['score'],
                'intent' => $intentAnalysis['intent'],
                'confidence' => $intentAnalysis['confidence'],
                'is_lead' => $leadAnalysis['is_lead'],
                'lead_score' => $leadAnalysis['lead_score'],
            ];

            Log::info('Analysis complete', $analysis);

            // Update comment with analysis results
            $this->comment->update([
                'sentiment' => $analysis['sentiment'],
                'sentiment_score' => $analysis['sentiment_score'],
                'intent' => $analysis['intent'],
                'intent_confidence' => $analysis['confidence'],
                'lead_score' => $analysis['lead_score'],
                'is_lead' => $analysis['is_lead'],
                'ai_analysis_failed' => false,
                'ai_error_message' => null,
                'ai_analysis_completed_at' => now(),
            ]);

            Log::info('Comment updated with analysis: ' . $this->comment->id);

            // If it's a potential lead or support request, create Lead record
            if ($analysis['is_lead'] || $analysis['intent'] === 'sales' || $analysis['intent'] === 'support') {
                Log::info('Priority comment detected - lead/sales/support', [
                    'comment_id' => $this->comment->id,
                    'analysis' => $analysis,
                ]);

                $this->createOrUpdateLead($analysis);
            }

            // Always generate AI response for the comment
            GenerateOllamaResponse::dispatch($this->comment);

        } catch (\Exception $e) {
            Log::error('Ollama analysis failed: ' . $e->getMessage());

            $this->comment->update([
                'sentiment' => 'pending',
                'intent' => 'general',
                'lead_score' => 0,
                'is_lead' => false,
                'ai_analysis_failed' => true,
                'ai_error_message' => $e->getMessage(),
                'ai_analysis_completed_at' => now(),
            ]);
        }
    }

    private function createOrUpdateLead(array $analysis): void
    {
        $existingLead = Lead::where('social_comment_id', $this->comment->id)->first();

        if ($existingLead) {
            $existingLead->update([
                'lead_score' => $analysis['lead_score'],
                'lead_status' => $existingLead->lead_status === 'new' ? 'new' : $existingLead->lead_status,
            ]);

            Log::info('Lead updated: ' . $existingLead->id);
            return;
        }

        $leadType = match ($analysis['intent']) {
            'sales' => 'sales',
            'support' => 'support',
            default => 'sales',
        };

        Lead::create([
            'organization_id' => $this->comment->socialAccount->organization_id,
            'social_comment_id' => $this->comment->id,
            'platform_author_id' => $this->comment->platform_author_id ?? 'unknown_' . $this->comment->platform_comment_id,
            'author_name' => $this->comment->author_name,
            'author_profile_url' => $this->comment->author_avatar_url,
            'initial_message' => substr($this->comment->content, 0, 500),
            'lead_type' => $leadType,
            'lead_score' => $analysis['lead_score'],
            'lead_status' => 'new',
        ]);

        Log::info('Lead created for comment: ' . $this->comment->id);
    }
}
