<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeSource;
use App\Models\SocialPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SocialPostController extends Controller
{
    public function index(Request $request)
    {
        $organization = Auth::user()->organization;

        $posts = SocialPost::query()
            ->with('knowledgeSources')
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('content', 'like', "%{$request->search}%")
                        ->orWhere('platform_post_id', 'like', "%{$request->search}%");
                });
            })
            ->when($request->filled('platform'), fn ($q) => $q->where('platform', $request->platform))
            ->when($request->filled('has_knowledge'), function ($q) use ($request) {
                if ($request->has_knowledge == '1') {
                    $q->has('knowledgeSources');
                }

                if ($request->has_knowledge == '0') {
                    $q->doesntHave('knowledgeSources');
                }
            })
            ->latest('posted_at')
            ->paginate(20)
            ->withQueryString();
        
        $sources = KnowledgeSource::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->latest()
            ->get();

        return Inertia::render('SocialPosts/Index', [
            'posts' => $posts,
            'sources' => $sources,
            'filters' => $request->only(['search', 'platform', 'has_knowledge']),
        ]);
    }

    public function attachKnowledgeSources(Request $request, SocialPost $post)
    {
        $this->authorizePost($post);

        $validated = $request->validate([
            'source_ids' => ['array'],
            'source_ids.*' => ['integer', 'exists:knowledge_sources,id'],
        ]);

        $sourceIds = KnowledgeSource::where('organization_id', Auth::user()->organization_id)
            ->whereIn('id', $validated['source_ids'] ?? [])
            ->pluck('id')
            ->toArray();

        $syncData = [];

        foreach ($sourceIds as $sourceId) {
            $syncData[$sourceId] = [
                'organization_id' => Auth::user()->organization_id,
                'usage_type' => 'supporting',
                'is_active' => true,
            ];
        }

        $post->knowledgeSources()->sync($syncData);

        return back()->with('success', 'Knowledge sources attached successfully.');
    }

    public function unlinkKnowledgeSource(SocialPost $post, KnowledgeSource $source)
    {
        $this->authorizePost($post);

        if ($source->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $post->knowledgeSources()->detach($source->id);

        return back()->with('success', 'Knowledge source unlinked successfully.');
    }

    public function uploadKnowledgeSource(Request $request, SocialPost $post)
    {
        $this->authorizePost($post);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:pdf,docx,faq,script,policy,template,brochure'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $organization = Auth::user()->organization;
        $file = $request->file('file');

        $path = $file->store(
            "knowledge-sources/{$organization->id}",
            'public'
        );

        $source = KnowledgeSource::create([
            'organization_id' => $organization->id,
            'uploaded_by_user_id' => Auth::id(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'scope' => 'post_specific',
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'metadata' => [
                'mime_type' => $file->getMimeType(),
                'linked_post_id' => $post->id,
            ],
            'is_indexed' => false,
        ]);

        $post->knowledgeSources()->attach($source->id, [
            'organization_id' => $organization->id,
            'usage_type' => 'primary',
            'is_active' => true,
        ]);

        // Later dispatch actual indexing job:
        // IndexKnowledgeSource::dispatch($source);

        return back()->with('success', 'Knowledge source uploaded and linked successfully.');
    }

    private function authorizePost(SocialPost $post): void
    {
        if ($post->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }
    }
}