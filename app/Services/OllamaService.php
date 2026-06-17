<?php

// app/Services/OllamaService.php

namespace App\Services;

use App\Models\SocialComment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class OllamaService
{
    private string $ollamaUrl;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->ollamaUrl = env('OLLAMA_URL', 'http://localhost:11434');
        $this->model = env('OLLAMA_MODEL', 'gemma2:2b');
        $this->timeout = 60;
    }

    /**
     * Analyze a social media comment
     * Returns sentiment, intent, and lead score
     */
    public function analyzeComment(SocialComment $comment): array
    {
        try {
            Log::info('Analyzing comment: ' . $comment->id . ' with Ollama');

            $prompt = $this->buildAnalysisPrompt($comment->content);

            $response = $this->callOllama($prompt);

            // Parse the response
            $analysis = $this->parseAnalysisResponse($response, $comment->content);

            Log::info('Analysis complete for comment: ' . $comment->id, $analysis);

            return $analysis;

        } catch (\Exception $e) {
            Log::error('Error analyzing comment: ' . $e->getMessage());
            return $this->getDefaultAnalysis();
        }
    }

    /**
     * Generate AI response to a comment
     */
    public function generateResponse(SocialComment $comment, array $context = []): string
    {
        try {
            Log::info('Generating response for comment: ' . $comment->id);

            $prompt = $this->buildResponsePrompt(
                commentContent: $comment->content,
                authorName: $comment->author_name,
                context: $context
            );

            $response = $this->callOllama($prompt);

            Log::info('Response generated: ' . substr($response, 0, 100));

            return trim($response);

        } catch (\Exception $e) {
            Log::error('Error generating response: ' . $e->getMessage());
            return 'Thank you for your comment! We appreciate your feedback.';
        }
    }

    /**
     * Classify comment intent (sales, support, complaint, question, etc)
     */
    public function classifyIntent(string $content): array
    {
        try {
            $prompt = <<<PROMPT
                Classify the intent of this social media comment. Response MUST be ONLY one word from this list:
                - sales (asking about products/pricing)
                - support (needs help/has issue)
                - complaint (unhappy/negative)
                - question (asking for information)
                - lead (potential customer interest)
                - general (other)

                Comment: "$content"

                Response (single word only):
                PROMPT;

            $intent = trim($this->callOllama($prompt));

            // Validate intent
            $validIntents = ['sales', 'support', 'complaint', 'question', 'lead', 'general'];
            if (!in_array($intent, $validIntents)) {
                $intent = 'general';
            }

            return [
                'intent' => $intent,
                'confidence' => 0.85,
            ];

        } catch (\Exception $e) {
            Log::error('Error classifying intent: ' . $e->getMessage());
            return ['intent' => 'general', 'confidence' => 0.5];
        }
    }

    /**
     * Analyze sentiment (positive, negative, neutral)
     */
    public function analyzeSentiment(string $content): array
    {
        try {
            $prompt = <<<PROMPT
                Analyze the sentiment of this comment. Response MUST be ONLY one word:
                - positive
                - negative
                - neutral

                Comment: "$content"

                Response (single word only):
                PROMPT;

            $sentiment = trim(strtolower($this->callOllama($prompt)));

            // Validate sentiment
            if (!in_array($sentiment, ['positive', 'negative', 'neutral'])) {
                $sentiment = 'neutral';
            }

            $score = $this->calculateSentimentScore($sentiment, $content);

            return [
                'sentiment' => $sentiment,
                'score' => $score,
            ];

        } catch (\Exception $e) {
            Log::error('Error analyzing sentiment: ' . $e->getMessage());
            return ['sentiment' => 'neutral', 'score' => 50];
        }
    }

    /**
     * Detect if comment is from a potential lead
     */
    public function detectLead(string $content): array
    {
        try {
            $prompt = <<<PROMPT
                Is this a comment from a potential customer lead? Response MUST be YES or NO only.

                Comment: "$content"

                Response (YES or NO only):
                PROMPT;

            $response = trim(strtoupper($this->callOllama($prompt)));
            $isLead = strpos($response, 'YES') !== false;

            return [
                'is_lead' => $isLead,
                'lead_score' => $isLead ? 75 : 25,
            ];

        } catch (\Exception $e) {
            Log::error('Error detecting lead: ' . $e->getMessage());
            return ['is_lead' => false, 'lead_score' => 0];
        }
    }

    /**
     * Build analysis prompt
     */
    private function buildAnalysisPrompt(string $content): string
    {
        return <<<PROMPT
            Analyze this social media comment and provide a brief analysis.

            Comment: "$content"

            Provide analysis in this EXACT format:
            Sentiment: [positive/negative/neutral]
            Intent: [sales/support/complaint/question/lead/general]
            IsLead: [YES/NO]
            Score: [0-100]

            Analysis:
            PROMPT;
    }

    /**
     * Build response prompt
     */
    private function buildResponsePrompt(
        string $commentContent,
        string $authorName,
        array $context = []
    ): string {
        $knowledge = !empty($context['knowledge'])
            ? "\n\nRelevant Knowledge Base:\n{$context['knowledge']}"
            : "\n\nRelevant Knowledge Base:\nNo specific knowledge found.";

        $conversationHistory = !empty($context['conversation_history'])
            ? $context['conversation_history']
            : 'No previous conversation history.';

        return <<<PROMPT
            You are a customer service representative for a financial services company.

            Your job:
            - Understand the full conversation history before replying.
            - Reply only to the latest customer message.
            - Do not repeat information already given by the assistant.
            - Keep the reply brief, professional, and helpful.
            - Use 1-2 sentences only.
            - Do not mention that you are an AI.
            - Do not promise guaranteed returns or financial outcomes.
            - If the user asks for investment advice, suggest speaking with an advisor or sharing details for guidance.

            Relevant Knowledge Base:
            {$knowledge}

            Conversation History:
            {$conversationHistory}

            Latest Customer Message from {$authorName}:
            "{$commentContent}"

            Write the best reply:
            PROMPT;
    }

    /**
     * Call Ollama API
     */
    private function callOllama(string $prompt): string
    {
        try {
            Log::info('Calling Ollama at: ' . $this->ollamaUrl);

            $payload = [
                'model' => $this->model,
                'prompt' => $prompt,
                'stream' => false,
                'temperature' => 0.7,
            ];

            // Use cURL for better control
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->ollamaUrl . '/api/generate');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                Log::error('CURL Error: ' . $curlError);
                return '';
            }

            if ($httpCode !== 200) {
                Log::error('Ollama HTTP Error: ' . $httpCode . ' - ' . $response);
                return '';
            }

            $data = json_decode($response, true);

            if (isset($data['response'])) {
                return $data['response'];
            }

            Log::error('Unexpected Ollama response: ' . $response);
            return '';

        } catch (\Exception $e) {
            Log::error('Exception calling Ollama: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Parse analysis response
     */
    private function parseAnalysisResponse(string $response, string $commentContent): array
    {
        $analysis = $this->getDefaultAnalysis();

        // Try to extract sentiment
        if (preg_match('/sentiment:\s*(\w+)/i', $response, $matches)) {
            $sentiment = strtolower($matches[1]);
            if (in_array($sentiment, ['positive', 'negative', 'neutral'])) {
                $analysis['sentiment'] = $sentiment;
                $analysis['sentiment_score'] = $this->calculateSentimentScore($sentiment, $commentContent);
            }
        }

        // Try to extract intent
        if (preg_match('/intent:\s*(\w+)/i', $response, $matches)) {
            $intent = strtolower($matches[1]);
            if (in_array($intent, ['sales', 'support', 'complaint', 'question', 'lead', 'general'])) {
                $analysis['intent'] = $intent;
            }
        }

        // Try to extract lead indicator
        if (preg_match('/islead:\s*(yes|no)/i', $response, $matches)) {
            $analysis['is_lead'] = strtolower($matches[1]) === 'yes';
        }

        // Try to extract score
        if (preg_match('/score:\s*(\d+)/i', $response, $matches)) {
            $analysis['lead_score'] = intval($matches[1]);
        }

        return $analysis;
    }

    /**
     * Calculate sentiment score (0-100)
     */
    private function calculateSentimentScore(string $sentiment, string $content): int
    {
        $score = 50; // neutral

        if ($sentiment === 'positive') {
            $score = 75;
            // Boost if contains positive keywords
            if (preg_match('/(great|amazing|excellent|love|perfect|fantastic)/i', $content)) {
                $score = 90;
            }
        } elseif ($sentiment === 'negative') {
            $score = 25;
            // Lower if contains negative keywords
            if (preg_match('/(terrible|horrible|worst|hate|awful|disgusting)/i', $content)) {
                $score = 10;
            }
        }

        return $score;
    }

    /**
     * Get default analysis response
     */
    private function getDefaultAnalysis(): array
    {
        return [
            'sentiment' => 'neutral',
            'sentiment_score' => 50,
            'intent' => 'general',
            'confidence' => 0.5,
            'is_lead' => false,
            'lead_score' => 0,
        ];
    }

    /**
     * Check if Ollama is available
     */
    public function isAvailable(): bool
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->ollamaUrl . '/api/tags');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get available models
     */
    public function getAvailableModels(): array
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->ollamaUrl . '/api/tags');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);

            return $data['models'] ?? [];

        } catch (\Exception $e) {
            Log::error('Error getting models: ' . $e->getMessage());
            return [];
        }
    }
}