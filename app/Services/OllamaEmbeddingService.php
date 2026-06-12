<?php

// app/Services/OllamaEmbeddingService.php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class OllamaEmbeddingService
{
    private string $ollamaUrl;
    private string $model;
    private int $timeout;
    private int $embeddingDim;

    public function __construct()
    {
        $this->ollamaUrl = env('OLLAMA_URL', 'http://localhost:11434');
        $this->model = env('KB_EMBEDDING_MODEL', 'nomic-embed-text');
        $this->timeout = 30;
        $this->embeddingDim = env('KB_EMBEDDING_DIM', 384);
    }

    /**
     * Generate embedding for a text chunk
     */
    public function embed(string $text): array
    {
        try {
            Log::info('Generating embedding for text: ' . substr($text, 0, 50) . '...');

            $vector = $this->callOllamaEmbedding($text);

            if (empty($vector)) {
                Log::warning('Empty embedding received');
                return [];
            }

            Log::info('Generated embedding with ' . count($vector) . ' dimensions');
            return $vector;

        } catch (\Exception $e) {
            Log::error('Error generating embedding: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    public function similarity(array $vector1, array $vector2): float
    {
        if (empty($vector1) || empty($vector2) || count($vector1) !== count($vector2)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $norm1 += $vector1[$i] * $vector1[$i];
            $norm2 += $vector2[$i] * $vector2[$i];
        }

        if ($norm1 == 0 || $norm2 == 0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($norm1) * sqrt($norm2));
    }

    /**
     * Batch embed multiple texts
     */
    public function batchEmbed(array $texts): array
    {
        try {
            Log::info('Batch embedding ' . count($texts) . ' texts');

            $embeddings = [];
            foreach ($texts as $index => $text) {
                Log::debug('Embedding text ' . ($index + 1) . '/' . count($texts));
                $embeddings[] = $this->embed($text);
            }

            Log::info('Batch embedding complete');
            return $embeddings;

        } catch (\Exception $e) {
            Log::error('Batch embedding error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Call Ollama API for embeddings
     */
    private function callOllamaEmbedding(string $text): array
    {
        try {
            // Use /api/embeddings endpoint
            $url = $this->ollamaUrl . '/api/embeddings';

            Log::debug('Calling Ollama embeddings at: ' . $url);

            $payload = [
                'model' => $this->model,
                'prompt' => $text,
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
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
                return [];
            }

            if ($httpCode !== 200) {
                Log::error('Ollama HTTP Error: ' . $httpCode . ' - ' . $response);
                return [];
            }

            $data = json_decode($response, true);

            if (isset($data['embedding'])) {
                return $data['embedding'];
            }

            if (isset($data['embeddings']) && is_array($data['embeddings'])) {
                return $data['embeddings'][0] ?? [];
            }

            Log::error('Unexpected response format: ' . $response);
            return [];

        } catch (\Exception $e) {
            Log::error('Exception in callOllamaEmbedding: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if embedding model is available
     */
    public function isModelAvailable(): bool
    {
        try {
            $models = $this->getAvailableModels();

            foreach ($models as $model) {
                if (isset($model['name']) && strpos($model['name'], $this->model) !== false) {
                    return true;
                }
            }

            return false;

        } catch (\Exception $e) {
            Log::warning('Error checking model availability: ' . $e->getMessage());
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            return $data['models'] ?? [];

        } catch (\Exception $e) {
            Log::error('Error getting models: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pull embedding model if not available
     */
    public function pullModel(): bool
    {
        try {
            Log::info('Pulling embedding model: ' . $this->model);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->ollamaUrl . '/api/pull');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);  // 5 minutes for download
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'name' => $this->model,
            ]));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;

        } catch (\Exception $e) {
            Log::error('Error pulling model: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get embedding dimensions
     */
    public function getDimensions(): int
    {
        return $this->embeddingDim;
    }

    /**
     * Test embedding service
     */
    public function test(): bool
    {
        try {
            Log::info('Testing embedding service');

            $testText = 'This is a test embedding.';
            $embedding = $this->embed($testText);

            if (empty($embedding)) {
                Log::error('Test failed: empty embedding');
                return false;
            }

            Log::info('Test successful. Embedding dimensions: ' . count($embedding));
            return true;

        } catch (\Exception $e) {
            Log::error('Test failed: ' . $e->getMessage());
            return false;
        }
    }
}