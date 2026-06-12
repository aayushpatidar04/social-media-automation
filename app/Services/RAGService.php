<?php

// app/Services/RAGService.php

namespace App\Services;

use App\Models\KnowledgeChunk;
use Illuminate\Support\Facades\Log;

class RAGService
{
    private OllamaEmbeddingService $embeddingService;
    private float $similarityThreshold;

    public function __construct()
    {
        $this->embeddingService = new OllamaEmbeddingService();
        $this->similarityThreshold = 0.3;  // Only return chunks with >30% similarity
    }

    /**
     * Retrieve relevant knowledge chunks for a query
     */
    public function retrieve(string $query, int $organizationId, int $topK = 3): array
    {
        try {
            Log::info('Retrieving knowledge for query: ' . substr($query, 0, 50) . '...');

            // Generate embedding for query
            $queryEmbedding = $this->embeddingService->embed($query);

            if (empty($queryEmbedding)) {
                Log::warning('Failed to generate query embedding');
                return [];
            }

            // Get all chunks for this organization
            $chunks = KnowledgeChunk::where('organization_id', $organizationId)
                ->with('knowledgeSource')
                ->get();

            Log::info('Searching through ' . $chunks->count() . ' chunks');

            // Calculate similarity for each chunk
            $results = [];
            foreach ($chunks as $chunk) {
                if (empty($chunk->embedding)) {
                    continue;
                }

                $chunkEmbedding = is_string($chunk->embedding) 
                    ? json_decode($chunk->embedding, true) 
                    : $chunk->embedding;

                if (empty($chunkEmbedding)) {
                    continue;
                }

                $similarity = $this->embeddingService->similarity($queryEmbedding, $chunkEmbedding);

                if ($similarity >= $this->similarityThreshold) {
                    $results[] = [
                        'chunk' => $chunk,
                        'similarity' => $similarity,
                        'source' => $chunk->knowledgeSource->title ?? 'Unknown',
                    ];
                }
            }

            // Sort by similarity (highest first)
            usort($results, function($a, $b) {
                return $b['similarity'] <=> $a['similarity'];
            });

            // Return top K results
            $topResults = array_slice($results, 0, $topK);

            Log::info('Retrieved ' . count($topResults) . ' relevant chunks');

            return $topResults;

        } catch (\Exception $e) {
            Log::error('Error retrieving knowledge: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Build context string from retrieved chunks
     */
    public function buildContext(array $retrievedChunks): string
    {
        if (empty($retrievedChunks)) {
            return '';
        }

        $context = "Based on our knowledge base:\n\n";

        foreach ($retrievedChunks as $result) {
            $chunk = $result['chunk'];
            $source = $result['source'];
            $similarity = round($result['similarity'] * 100, 1);

            $context .= "From {$source} (relevance: {$similarity}%):\n";
            $context .= $chunk->content . "\n\n";
        }

        return $context;
    }

    /**
     * Build prompt with context
     */
    public function buildPrompt(string $query, array $retrievedChunks, string $systemPrompt = ''): string
    {
        $context = $this->buildContext($retrievedChunks);

        $prompt = $systemPrompt ? $systemPrompt . "\n\n" : '';
        $prompt .= "Knowledge Base Context:\n";
        $prompt .= $context;
        $prompt .= "\nUser Query: " . $query . "\n";
        $prompt .= "Based on the above knowledge base, please respond to the user query.";

        return $prompt;
    }

    /**
     * Index all chunks for an organization
     */
    public function reindexOrganization(int $organizationId): int
    {
        try {
            Log::info('Reindexing knowledge for organization: ' . $organizationId);

            $chunks = KnowledgeChunk::where('organization_id', $organizationId)->get();
            $count = 0;

            foreach ($chunks as $chunk) {
                $embedding = $this->embeddingService->embed($chunk->content);
                
                if (!empty($embedding)) {
                    $chunk->update([
                        'embedding' => json_encode($embedding),
                    ]);
                    $count++;
                }
            }

            Log::info('Reindexed ' . $count . ' chunks');
            return $count;

        } catch (\Exception $e) {
            Log::error('Reindexing error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Search knowledge base with full text search
     */
    public function fullTextSearch(string $query, int $organizationId, int $limit = 5): array
    {
        try {
            $keywords = preg_split('/\s+/', strtolower($query));

            $chunks = KnowledgeChunk::where('organization_id', $organizationId)
                ->with('knowledgeSource')
                ->get();

            $results = [];
            foreach ($chunks as $chunk) {
                $contentLower = strtolower($chunk->content);
                $matchCount = 0;

                foreach ($keywords as $keyword) {
                    if (strpos($contentLower, $keyword) !== false) {
                        $matchCount++;
                    }
                }

                if ($matchCount > 0) {
                    $results[] = [
                        'chunk' => $chunk,
                        'matches' => $matchCount,
                        'source' => $chunk->knowledgeSource->title ?? 'Unknown',
                    ];
                }
            }

            // Sort by match count
            usort($results, function($a, $b) {
                return $b['matches'] <=> $a['matches'];
            });

            return array_slice($results, 0, $limit);

        } catch (\Exception $e) {
            Log::error('Full text search error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get knowledge statistics for an organization
     */
    public function getStats(int $organizationId): array
    {
        try {
            $chunks = KnowledgeChunk::where('organization_id', $organizationId)->get();
            $sources = \App\Models\KnowledgeSource::where('organization_id', $organizationId)->get();

            $totalTokens = $chunks->sum('tokens');

            return [
                'total_sources' => $sources->count(),
                'total_chunks' => $chunks->count(),
                'total_tokens' => $totalTokens,
                'avg_chunk_tokens' => $chunks->count() > 0 ? $totalTokens / $chunks->count() : 0,
                'indexed_chunks' => $chunks->where('embedding', '!=', null)->count(),
            ];

        } catch (\Exception $e) {
            Log::error('Error getting stats: ' . $e->getMessage());
            return [];
        }
    }
}