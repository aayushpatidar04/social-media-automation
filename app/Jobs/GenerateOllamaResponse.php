<?php

// app/Jobs/GenerateAIResponse.php - USING OLLAMA

namespace App\Jobs;

use App\Models\SocialComment;
use App\Models\AiConversation;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateOllamaResponse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;

    private SocialComment $comment;

    public function __construct(SocialComment $comment)
    {
        $this->comment = $comment;
    }

    public function handle()
    {
        try {
            Log::info('🤖 Generating AI response for comment: ' . $this->comment->id);

            $service = new OllamaService();

            // Build context
            $context = [
                'knowledge' => $this->getRelevantKnowledge($this->comment),
            ];

            // Generate response
            $response = $service->generateResponse(
                $this->comment,
                $context
            );

            if (empty($response)) {
                Log::warning('⚠️  Empty response from AI');
                return;
            }

            Log::info('✅ Generated response: ' . substr($response, 0, 100));

            // Store the AI conversation
            $aiConversation = AiConversation::create([
                'organization_id' => $this->comment->organization_id,
                'social_comment_id' => $this->comment->id,
                'ai_response' => $response,
                'response_status' => 'pending', // Waiting for human approval
                'confidence' => 0.8,
                'model_used' => 'ollama_gemma2',
            ]);

            Log::info('✅ AI conversation stored: ' . $aiConversation->id);

            // Update comment status
            $this->comment->update([
                'ai_response_text' => $response,
                'status' => 'pending_approval',
            ]);

            Log::info('✅ Comment status updated to pending_approval');

        } catch (\Exception $e) {
            Log::error('❌ Error generating response: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get relevant knowledge from knowledge base
     */
    private function getRelevantKnowledge(SocialComment $comment): string
    {
        try {
            // Get relevant knowledge from knowledge base
            // For now, return empty - you can implement RAG here
            
            $knowledge = \App\Models\KnowledgeSource::where('organization_id', $comment->organization_id)
                // ->where('is_active', true)
                ->limit(3)
                ->get()
                ->pluck('content')
                ->join("\n\n");

            return $knowledge ?: '';

        } catch (\Exception $e) {
            Log::warning('Could not retrieve knowledge: ' . $e->getMessage());
            return '';
        }
    }
}