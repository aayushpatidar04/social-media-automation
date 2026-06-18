<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeSource;
use App\Models\SocialPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class KnowledgeSourceController extends Controller
{
    public function index(Request $request)
    {
        $organization = Auth::user()->organization;

        $sources = KnowledgeSource::query()
            ->with('socialPosts')
            ->where('organization_id', $organization->id)
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('name', 'like', "%{$request->search}%")
                        ->orWhere('description', 'like', "%{$request->search}%")
                        ->orWhere('original_filename', 'like', "%{$request->search}%");
                });
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->scope))
            ->when($request->filled('indexed'), fn ($q) => $q->where('is_indexed', (bool) $request->indexed))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $posts = SocialPost::query()
            ->where('organization_id', $organization->id)
            ->latest('posted_at')
            ->limit(100)
            ->get();

        return Inertia::render('KnowledgeSources/Index', [
            'sources' => $sources,
            'posts' => $posts,
            'filters' => $request->only(['search', 'type', 'scope', 'indexed']),
        ]);
    }

    public function store(Request $request)
    {
        $organization = Auth::user()->organization;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:pdf,docx,faq,script,policy,template,brochure'],
            'scope' => ['required', 'in:global,post_specific'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');

        $path = $file->store(
            "knowledge-sources/{$organization->id}",
            'public'
        );

        KnowledgeSource::create([
            'organization_id' => $organization->id,
            'uploaded_by_user_id' => Auth::id(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'scope' => $validated['scope'],
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'metadata' => [
                'mime_type' => $file->getMimeType(),
            ],
            'is_indexed' => false,
        ]);

        return back()->with('success', 'Knowledge source uploaded successfully.');
    }

    public function linkPosts(Request $request, KnowledgeSource $source)
    {
        $this->authorizeSource($source);

        $validated = $request->validate([
            'post_ids' => ['array'],
            'post_ids.*' => ['integer', 'exists:social_posts,id'],
        ]);

        $postIds = SocialPost::where('organization_id', Auth::user()->organization_id)
            ->whereIn('id', $validated['post_ids'] ?? [])
            ->pluck('id')
            ->toArray();

        $syncData = [];

        foreach ($postIds as $postId) {
            $syncData[$postId] = [
                'organization_id' => Auth::user()->organization_id,
                'usage_type' => 'supporting',
                'is_active' => true,
            ];
        }

        $source->socialPosts()->sync($syncData);

        return back()->with('success', 'Posts linked successfully.');
    }

    public function unlinkPost(KnowledgeSource $source, SocialPost $post)
    {
        $this->authorizeSource($source);

        if ($post->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $source->socialPosts()->detach($post->id);

        return back()->with('success', 'Post unlinked successfully.');
    }

    public function reindex(KnowledgeSource $source)
    {
        $this->authorizeSource($source);

        $source->update([
            'is_indexed' => false,
            'indexed_at' => null,
        ]);

        // Later dispatch actual extraction/chunking job:
        // IndexKnowledgeSource::dispatch($source);

        return back()->with('success', 'Re-index queued successfully.');
    }

    public function destroy(KnowledgeSource $source)
    {
        $this->authorizeSource($source);

        if ($source->file_path && Storage::disk('public')->exists($source->file_path)) {
            Storage::disk('public')->delete($source->file_path);
        }

        $source->delete();

        return back()->with('success', 'Knowledge source deleted successfully.');
    }

    private function authorizeSource(KnowledgeSource $source): void
    {
        if ($source->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }
    }
}