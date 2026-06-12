<?php

// app/Jobs/AnalyzeCommentWithAI.php - UPDATED FOR OLLAMA

namespace App\Jobs;

use App\Models\SocialComment;
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

    public $timeout = 120;  // Ollama might be slower than API
    public $tries = 2;
    public $maxExceptions = 2;

    private SocialComment $comment;

    public function __construct(SocialComment $comment)
    {
        $this->comment = $comment;
    }

    public function handle()
    {
        try {
            Log::info('🤖 Starting AI analysis for comment: ' . $this->comment->id);

            $service = new OllamaService();

            // Check if Ollama is available
            if (!$service->isAvailable()) {
                Log::error('❌ Ollama service not available at ' . env('OLLAMA_URL', 'http://localhost:11434'));
                Log::warning('⚠️  AI analysis skipped - Ollama not running');
                $this->comment->update([
                    'ai_analysis_failed' => true,
                    'ai_error_message' => 'Ollama service not available',
                    'ai_analysis_completed_at' => now(),
                ]);
                return;
            }

            // Step 1: Analyze sentiment
            Log::info('📊 Analyzing sentiment...');
            $sentimentAnalysis = $service->analyzeSentiment($this->comment->content);

            // Step 2: Classify intent
            Log::info('🎯 Classifying intent...');
            $intentAnalysis = $service->classifyIntent($this->comment->content);

            // Step 3: Detect lead
            Log::info('👤 Detecting lead...');
            $leadAnalysis = $service->detectLead($this->comment->content);

            // Compile results
            $analysis = [
                'sentiment' => $sentimentAnalysis['sentiment'],
                'sentiment_score' => $sentimentAnalysis['score'],
                'intent' => $intentAnalysis['intent'],
                'confidence' => $intentAnalysis['confidence'],
                'is_lead' => $leadAnalysis['is_lead'],
                'lead_score' => $leadAnalysis['lead_score'],
            ];

            Log::info('✅ Analysis complete', $analysis);

            // Update comment with analysis results
            $this->comment->update([
                'sentiment' => $analysis['sentiment'],
                'sentiment_score' => $analysis['sentiment_score'],
                'intent' => $analysis['intent'],
                'intent_confidence' => $analysis['confidence'],
                'lead_score' => $analysis['lead_score'],
                'is_lead' => $analysis['is_lead'],
                'ai_analysis_completed_at' => now(),
                'ai_analysis_failed' => false,
            ]);

            Log::info('✅ Comment updated with analysis: ' . $this->comment->id);

            // If it's a potential lead or support request, generate AI response
            if ($analysis['is_lead'] || $analysis['intent'] === 'sales' || $analysis['intent'] === 'support') {
                Log::info('🤖 Generating AI response...');
                GenerateAIResponse::dispatch($this->comment);
            }

            // Broadcast update
            try {
                // broadcast(new \App\Events\CommentAnalyzed($this->comment));
            } catch (\Exception $e) {
                Log::warning('Could not broadcast event: ' . $e->getMessage());
            }

        } catch (\Exception $e) {
            Log::error('❌ AI analysis failed for comment: ' . $this->comment->id);
            Log::error('Error: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());

            $this->comment->update([
                'ai_analysis_failed' => true,
                'ai_error_message' => $e->getMessage(),
                'ai_analysis_completed_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(\Exception $exception)
    {
        Log::error('🔴 AI analysis job permanently failed for comment: ' . $this->comment->id);
        Log::error('Reason: ' . $exception->getMessage());

        $this->comment->update([
            'ai_analysis_failed' => true,
            'ai_error_message' => 'Job failed after ' . $this->tries . ' attempts',
            'ai_analysis_completed_at' => now(),
        ]);
    }
}