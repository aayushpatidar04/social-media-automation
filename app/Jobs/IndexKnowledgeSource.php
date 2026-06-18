<?php

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory;

class IndexKnowledgeSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(
        public KnowledgeSource $source
    ) {
    }

    public function handle(): void
    {
        $source = $this->source->fresh();

        if (!$source) {
            return;
        }

        try {
            $source->update([
                'is_indexed' => false,
                'indexed_at' => null,
            ]);

            KnowledgeChunk::where('knowledge_source_id', $source->id)->delete();

            $text = $this->extractText($source);
            $text = $this->cleanText($text);

            if (!$text) {
                throw new \Exception('No text extracted from source.');
            }

            $chunks = $this->chunkText($text);

            foreach ($chunks as $index => $chunk) {
                KnowledgeChunk::create([
                    'knowledge_source_id' => $source->id,
                    'chunk_number' => $index + 1,
                    'content' => $chunk,
                    'token_count' => str_word_count($chunk),
                    'embedding' => null,
                    'embedding_model' => 'ollama-local',
                ]);
            }

            $source->update([
                'raw_text' => $text,
                'total_chunks' => count($chunks),
                'is_indexed' => true,
                'indexed_at' => now(),
            ]);

            Log::info('Knowledge source indexed', [
                'source_id' => $source->id,
                'chunks' => count($chunks),
            ]);
        } catch (\Throwable $e) {
            Log::error('Knowledge indexing failed', [
                'source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function extractText(KnowledgeSource $source): string
    {
        $path = Storage::disk('public')->path($source->file_path);
        $extension = strtolower(pathinfo($source->original_filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => $this->extractPdfText($path),
            'docx' => $this->extractDocxText($path),
            'txt', 'md', 'csv' => file_get_contents($path),
            default => throw new \Exception("Unsupported file type: {$extension}"),
        };
    }

    private function extractPdfText(string $path): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);

        return $pdf->getText();
    }

    private function extractDocxText(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $value = $element->getText();

                    if (is_string($value)) {
                        $text .= $value . "\n";
                    }
                }
            }
        }

        return $text;
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function chunkText(string $text, int $maxWords = 450): array
    {
        $words = preg_split('/\s+/', $text);
        $chunks = [];

        for ($i = 0; $i < count($words); $i += $maxWords) {
            $chunk = implode(' ', array_slice($words, $i, $maxWords));

            if (trim($chunk)) {
                $chunks[] = trim($chunk);
            }
        }

        return $chunks;
    }
}