<?php

// app/Services/DocumentChunkingService.php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentChunkingService
{
    private int $chunkSize;
    private int $chunkOverlap;

    public function __construct()
    {
        $this->chunkSize = env('KB_CHUNK_SIZE', 500);
        $this->chunkOverlap = env('KB_CHUNK_OVERLAP', 50);
    }

    /**
     * Extract text from document based on file type
     */
    public function extractText(string $filePath): string
    {
        try {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            Log::info('Extracting text from: ' . $filePath . ' (' . $extension . ')');

            return match ($extension) {
                'pdf' => $this->extractFromPDF($filePath),
                'txt' => $this->extractFromTXT($filePath),
                'docx' => $this->extractFromDOCX($filePath),
                'md' => $this->extractFromTXT($filePath),
                'json' => $this->extractFromJSON($filePath),
                default => throw new \Exception('Unsupported file type: ' . $extension),
            };

        } catch (\Exception $e) {
            Log::error('Error extracting text: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract text from PDF
     */
    private function extractFromPDF(string $filePath): string
    {
        try {
            $fullPath = Storage::disk('local')->path($filePath);
            $parser = new PdfParser();
            $pdf = $parser->parseFile($fullPath);

            $text = '';
            foreach ($pdf->getPages() as $page) {
                $text .= $page->getText();
            }

            Log::info('Extracted ' . strlen($text) . ' characters from PDF');
            return $text;

        } catch (\Exception $e) {
            Log::error('PDF extraction error: ' . $e->getMessage());
            throw new \Exception('Failed to extract text from PDF: ' . $e->getMessage());
        }
    }

    /**
     * Extract text from TXT
     */
    private function extractFromTXT(string $filePath): string
    {
        try {
            $text = Storage::get($filePath);
            Log::info('Extracted ' . strlen($text) . ' characters from TXT');
            return $text;

        } catch (\Exception $e) {
            Log::error('TXT extraction error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract text from DOCX
     */
    private function extractFromDOCX(string $filePath): string
    {
        try {
            $fullPath = Storage::disk('local')->path($filePath);

            // Use unzip to extract document.xml
            $zip = new \ZipArchive();
            if (!$zip->open($fullPath)) {
                throw new \Exception('Cannot open DOCX file');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if (!$xml) {
                throw new \Exception('Cannot find document.xml in DOCX');
            }

            // Simple XML parsing for text
            $dom = new \DOMDocument();
            $dom->loadXML($xml);

            $text = '';
            foreach ($dom->getElementsByTagName('t') as $node) {
                $text .= $node->textContent . ' ';
            }

            Log::info('Extracted ' . strlen($text) . ' characters from DOCX');
            return $text;

        } catch (\Exception $e) {
            Log::error('DOCX extraction error: ' . $e->getMessage());
            throw new \Exception('Failed to extract text from DOCX: ' . $e->getMessage());
        }
    }

    /**
     * Extract text from JSON
     */
    private function extractFromJSON(string $filePath): string
    {
        try {
            $content = Storage::get($filePath);
            $json = json_decode($content, true);

            if (!$json) {
                throw new \Exception('Invalid JSON');
            }

            // Convert JSON to readable text
            $text = json_encode($json, JSON_PRETTY_PRINT);
            Log::info('Extracted ' . strlen($text) . ' characters from JSON');
            return $text;

        } catch (\Exception $e) {
            Log::error('JSON extraction error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Split text into chunks
     */
    public function chunkText(string $text): array
    {
        try {
            Log::info('Chunking text. Size: ' . strlen($text) . ' chars, Chunk size: ' . $this->chunkSize);

            // Split by paragraphs first
            $paragraphs = preg_split('/\n\n+/', $text);

            $chunks = [];
            $currentChunk = '';
            $currentTokens = 0;

            foreach ($paragraphs as $paragraph) {
                $paragraphTokens = $this->estimateTokens($paragraph);

                // If adding paragraph would exceed chunk size
                if ($currentTokens + $paragraphTokens > $this->chunkSize && !empty($currentChunk)) {
                    // Save current chunk with overlap
                    $chunks[] = [
                        'content' => trim($currentChunk),
                        'tokens' => $currentTokens,
                    ];

                    // Create overlap
                    $words = explode(' ', $currentChunk);
                    $overlapWords = array_slice($words, -$this->chunkOverlap);
                    $currentChunk = implode(' ', $overlapWords) . '\n\n' . $paragraph;
                    $currentTokens = $this->estimateTokens($currentChunk);

                } else {
                    // Add to current chunk
                    if (!empty($currentChunk)) {
                        $currentChunk .= '\n\n';
                    }
                    $currentChunk .= $paragraph;
                    $currentTokens += $paragraphTokens;
                }
            }

            // Add final chunk
            if (!empty($currentChunk)) {
                $chunks[] = [
                    'content' => trim($currentChunk),
                    'tokens' => $currentTokens,
                ];
            }

            Log::info('Created ' . count($chunks) . ' chunks');
            return $chunks;

        } catch (\Exception $e) {
            Log::error('Chunking error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Clean and normalize text
     */
    public function cleanText(string $text): string
    {
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Remove special characters but keep punctuation
        $text = preg_replace('/[^\w\s\.\,\!\?\-\:\;\(\)]/u', '', $text);

        // Remove leading/trailing whitespace
        $text = trim($text);

        return $text;
    }

    /**
     * Estimate tokens (rough: 1 token ≈ 4 characters)
     */
    public function estimateTokens(string $text): int
    {
        return max(1, intval(strlen($text) / 4));
    }

    /**
     * Get document statistics
     */
    public function getStats(string $text, array $chunks): array
    {
        return [
            'total_characters' => strlen($text),
            'total_paragraphs' => count(preg_split('/\n\n+/', $text)),
            'estimated_tokens' => $this->estimateTokens($text),
            'chunk_count' => count($chunks),
            'avg_chunk_size' => $chunks ? array_sum(array_column($chunks, 'tokens')) / count($chunks) : 0,
        ];
    }
}