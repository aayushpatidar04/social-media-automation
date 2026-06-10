<?php

// app/Http/Controllers/KnowledgeBaseController.php

namespace App\Http\Controllers;

use App\Models\KnowledgeSource;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class KnowledgeBaseController extends Controller
{
    public function index(): Response
    {
        $organization = Auth::user()->organization;

        $sources = $organization->knowledgeSources()
            ->with('uploadedBy')
            ->latest('created_at')
            ->paginate(20);

        return Inertia::render('Settings/KnowledgeBase', [
            'sources' => $sources,
        ]);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'required|in:pdf,docx,faq,script,policy,template,brochure',
            'file' => 'required|file|max:10240', // 10MB
        ]);

        $organization = Auth::user()->organization;

        // Store file
        $file = $request->file('file');
        $filePath = $file->store('knowledge-base', 'private');
        $fileSize = $file->getSize();

        // Extract text (basic implementation)
        $text = $this->extractText($filePath, $request->type);

        // Create knowledge source
        $source = $organization->knowledgeSources()->create([
            'uploaded_by_user_id' => Auth::id(),
            'name' => $request->name,
            'description' => $request->description,
            'type' => $request->type,
            'file_path' => $filePath,
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $fileSize,
            'raw_text' => $text,
            'is_indexed' => false,
        ]);

        // Dispatch chunking job (for future embedding)
        \App\Jobs\ChunkKnowledgeSource::dispatch($source);

        // Log activity
        \App\Models\ActivityLog::create([
            'organization_id' => $organization->id,
            'user_id' => Auth::id(),
            'action' => 'knowledge_source_uploaded',
            'entity_type' => 'knowledge_source',
            'entity_id' => $source->id,
        ]);

        return response()->json([
            'message' => 'Knowledge source uploaded successfully',
            'source' => $source,
        ]);
    }

    public function delete(KnowledgeSource $source)
    {
        $this->authorize('delete', $source);

        // Delete file
        Storage::disk('private')->delete($source->file_path);

        // Delete chunks
        $source->knowledgeChunks()->delete();

        // Delete source
        $source->delete();

        // Log activity
        \App\Models\ActivityLog::create([
            'organization_id' => $source->organization_id,
            'user_id' => Auth::id(),
            'action' => 'knowledge_source_deleted',
            'entity_type' => 'knowledge_source',
            'entity_id' => $source->id,
        ]);

        return response()->json(['message' => 'Knowledge source deleted']);
    }

    /**
     * Extract text from uploaded file
     */
    private function extractText(string $filePath, string $type): ?string
    {
        try {
            $fullPath = Storage::disk('private')->path($filePath);

            if ($type === 'pdf') {
                // For PDF, you can use a library like spatie/pdf-to-text
                // For now, return null (can be enhanced later)
                return null;
            }

            if ($type === 'docx') {
                // For DOCX, can use a library
                return null;
            }

            // For other types, return null or file content
            return file_get_contents($fullPath);
        } catch (\Exception $e) {
            Log::error('Text extraction error: ' . $e->getMessage());
            return null;
        }
    }
}
