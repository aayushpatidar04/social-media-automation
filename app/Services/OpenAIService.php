<?php

// app/Services/OpenAIService.php

namespace App\Services;

use OpenAI\Client;
use OpenAI\Factory;
use Illuminate\Support\Facades\Log;
use App\Models\SocialComment;
use App\Models\KnowledgeChunk;
use Illuminate\Support\Facades\Cache;

class OpenAIService
{
    private Client $client;
    private const EMBEDDING_MODEL = 'text-embedding-3-small';
    private const ANALYSIS_MODEL = 'gpt-4.1-mini';
    private const RESPONSE_MODEL = 'gpt-4.1-mini';

    public function __construct()
    {
        // Use the Factory to create a Client instance
        $this->client = (new Factory())
            ->withApiKey(env('OPENAI_API_KEY'))
            ->make();
    }

    /**
     * Analyze a social media comment
     * Returns: sentiment, intent, lead_score, confidence
     */
    public function analyzeComment(SocialComment $comment): array
    {
        $prompt = $this->buildAnalysisPrompt($comment->content);

        try {
            $response = $this->client->chat()->create([
                'model' => self::ANALYSIS_MODEL,
                'temperature' => 0.3,
                'max_tokens' => 500,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert social media comment analyzer. Analyze comments and provide structured JSON responses.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

            $content = $response->choices[0]->message->content;

            // Extract JSON from response
            preg_match('/\{.*\}/s', $content, $matches);
            if (!$matches) {
                return $this->getDefaultAnalysis();
            }

            $analysis = json_decode($matches[0], true);

            return [
                'sentiment' => $analysis['sentiment'] ?? 'neutral',
                'sentiment_score' => intval($analysis['sentiment_score'] ?? 50),
                'intent' => $analysis['intent'] ?? 'general',
                'lead_score' => intval($analysis['lead_score'] ?? 0),
                'is_lead' => intval($analysis['lead_score'] ?? 0) > 30,
                'confidence' => intval($analysis['confidence'] ?? 50),
                'summary' => $analysis['summary'] ?? '',
            ];
        } catch (\Exception $e) {
            Log::error('OpenAI Analysis Error: ' . $e->getMessage());
            return $this->getDefaultAnalysis();
        }
    }

    /**
     * Generate an AI response for a comment
     */
    public function generateResponse(
        SocialComment $comment,
        string $intent,
        array $knowledgeContext = []
    ): array {
        // Get relevant knowledge base context
        if (empty($knowledgeContext)) {
            $knowledgeContext = $this->retrieveRelevantKnowledge(
                $comment->socialAccount->organization_id,
                $comment->content
            );
        }

        $prompt = $this->buildResponsePrompt(
            $comment->content,
            $intent,
            $knowledgeContext
        );

        try {
            $response = $this->client->chat()->create([
                'model' => self::RESPONSE_MODEL,
                'temperature' => 0.7,
                'max_tokens' => 300,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful and professional social media manager. Generate concise, friendly responses.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

            $responseText = $response->choices[0]->message->content;

            // Validate response length (platform specific)
            $responseText = $this->truncateForPlatform($responseText, $comment->socialAccount->platform);

            return [
                'response' => $responseText,
                'confidence' => 85,
                'requires_review' => $this->shouldRequireReview($intent),
                'review_reason' => $this->getReviewReason($intent),
            ];
        } catch (\Exception $e) {
            Log::error('OpenAI Response Generation Error: ' . $e->getMessage());
            return [
                'response' => null,
                'confidence' => 0,
                'requires_review' => true,
                'review_reason' => 'AI generation failed',
            ];
        }
    }

    /**
     * Retrieve relevant knowledge from vector database using RAG
     */
    public function retrieveRelevantKnowledge(int $organizationId, string $query, int $limit = 5): array
    {
        try {
            // Get embedding for the query
            $queryEmbedding = $this->getEmbedding($query);

            // Search similar chunks in knowledge base
            $relevantChunks = KnowledgeChunk::whereHas('knowledgeSource', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId)
                    ->where('is_indexed', true);
            })
                ->select('id', 'content', 'knowledge_source_id')
                ->limit($limit)
                ->get();

            // TODO: Implement vector similarity search when vector DB is ready
            // For now, return content-based matches

            return $relevantChunks->map(function ($chunk) {
                return [
                    'source' => $chunk->knowledgeSource->name,
                    'content' => $chunk->content,
                    'relevance' => 0.85,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::error('Knowledge Retrieval Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get embedding for text (for vector database)
     */
    public function getEmbedding(string $text): array
    {
        $cacheKey = 'embedding:' . md5($text);

        return Cache::remember($cacheKey, 86400, function () use ($text) {
            try {
                $response = $this->client->embeddings()->create([
                    'model' => self::EMBEDDING_MODEL,
                    'input' => $text,
                ]);

                return $response->embeddings[0]->embedding;
            } catch (\Exception $e) {
                Log::error('Embedding Error: ' . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Build analysis prompt
     */
    private function buildAnalysisPrompt(string $comment): string
    {
        return <<<PROMPT
        Analyze this social media comment and provide structured JSON response:

        Comment: "$comment"

        Return ONLY valid JSON (no markdown, no extra text):
        {
        "sentiment": "positive|neutral|negative",
        "sentiment_score": <0-100>,
        "intent": "sales|support|complaint|question|general|lead",
        "lead_score": <0-100>,
        "confidence": <0-100>,
        "summary": "brief summary"
        }
        PROMPT;
    }

    /**
     * Build response prompt
     */
    private function buildResponsePrompt(
        string $comment,
        string $intent,
        array $knowledgeContext
    ): string {
        $contextText = !empty($knowledgeContext)
            ? "Knowledge Base References:\n" . implode(
                "\n\n",
                array_map(fn($k) => "- {$k['source']}: {$k['content']}", $knowledgeContext)
            )
            : "No specific knowledge base information available.";

        return <<<PROMPT
You are responding to a social media comment. Provide a helpful, professional, concise response (max 280 characters).

Comment Type: $intent
Customer Comment: "$comment"

$contextText

Generate a response that:
1. Is friendly and professional
2. Addresses the intent
3. Fits the platform character limits
4. Avoids spam language

Response:
PROMPT;
    }

    /**
     * Truncate response for platform limits
     */
    private function truncateForPlatform(string $response, string $platform): string
    {
        $limits = [
            'facebook' => 63206,
            'instagram' => 2200,
            'youtube' => 10000,
            'twitter' => 280,
            'linkedin' => 3000,
        ];

        $limit = $limits[$platform] ?? 300;

        if (strlen($response) <= $limit) {
            return $response;
        }

        return substr($response, 0, $limit - 3) . '...';
    }

    /**
     * Determine if response requires human review
     */
    private function shouldRequireReview(string $intent): bool
    {
        $requiresReview = ['complaint', 'sales'];
        return in_array($intent, $requiresReview);
    }

    /**
     * Get reason why response requires review
     */
    private function getReviewReason(string $intent): ?string
    {
        $reasons = [
            'complaint' => 'Complaint detected - review recommended',
            'sales' => 'Sales inquiry detected - review recommended',
        ];
        return $reasons[$intent] ?? null;
    }

    /**
     * Default analysis response when AI fails
     */
    private function getDefaultAnalysis(): array
    {
        return [
            'sentiment' => 'pending',
            'sentiment_score' => 50,
            'intent' => 'pending',
            'lead_score' => 0,
            'is_lead' => false,
            'confidence' => 0,
            'summary' => 'Analysis pending - manual review required',
        ];
    }
}