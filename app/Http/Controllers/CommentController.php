<?php

// app/Http/Controllers/CommentController.php - COMPLETE

namespace App\Http\Controllers;

use App\Models\SocialComment;
use App\Models\AiConversation;
use App\Services\FacebookService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;


class CommentController extends Controller
{
    /**
     * Show inbox with all comments
     */
    public function inbox(Request $request)
    {
        $organization = Auth::user()->organization;

        $query = $organization->socialComments()
            ->with(['socialAccount', 'socialPost'])
            ->latest('commented_at');

        // Apply filters
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        if ($request->has('sentiment') && $request->sentiment !== '') {
            $query->where('sentiment', $request->sentiment);
        }

        if ($request->has('intent') && $request->intent !== '') {
            $query->where('intent', $request->intent);
        }

        if ($request->has('platform') && $request->platform !== '') {
            $query->where('platform', $request->platform);
        }

        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('content', 'like', "%{$search}%")
                    ->orWhere('author_name', 'like', "%{$search}%");
            });
        }

        $comments = $query->paginate(20);

        return Inertia::render('Inbox', [
            'comments' => $comments,
            'filters' => $request->only(['status', 'sentiment', 'intent', 'platform', 'search']),
        ]);
    }

    /**
     * Filter comments (AJAX)
     */
    public function filter(Request $request)
    {
        $organization = Auth::user()->organization;

        $query = $organization->socialComments()
            ->with(['socialAccount', 'socialPost'])
            ->latest('commented_at');

        // Apply all filters
        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->sentiment) {
            $query->where('sentiment', $request->sentiment);
        }
        if ($request->intent) {
            $query->where('intent', $request->intent);
        }
        if ($request->platform) {
            $query->where('platform', $request->platform);
        }
        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('content', 'like', "%{$search}%")
                    ->orWhere('author_name', 'like', "%{$search}%");
            });
        }

        $comments = $query->paginate(20);

        return response()->json($comments);
    }

    /**
     * Show single comment
     */
    public function show(SocialComment $comment)
    {
        if ($comment->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $comment->load(['socialAccount', 'socialPost']);

        $aiConversation = AiConversation::where('social_comment_id', $comment->id)
            ->latest()
            ->first();

        return Inertia::render('Comments/Show', [
            'comment' => $comment,
            'aiConversation' => $aiConversation,
        ]);
    }

    /**
     * Send manual reply to a comment
     */
    public function sendReply(Request $request, SocialComment $comment)
    {
        if ($comment->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:500',
        ]);

        try {
            $account = $comment->socialAccount;

            // For now, store the reply as a pending response
            $aiConversation = AiConversation::create([
                'organization_id' => $comment->organization_id,
                'social_comment_id' => $comment->id,
                'user_id' => Auth::id(),
                'ai_response' => $validated['message'],
                'response_status' => 'manual',
                'confidence' => 1.0,
                'created_at' => now(),
            ]);

            // Update comment status
            $comment->update([
                'status' => 'replied',
            ]);

            Log::info('Manual reply stored for comment: ' . $comment->id);

            return response()->json([
                'message' => 'Reply stored successfully',
                'status' => 'success',
            ]);

        } catch (\Exception $e) {
            Log::error('Error storing reply: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to store reply',
            ], 500);
        }
    }

    /**
     * Approve AI response and send it
     */
    public function approveAIResponse(Request $request, SocialComment $comment)
    {
        if ($comment->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        try {
            $aiConversation = AiConversation::where('social_comment_id', $comment->id)
                ->latest()
                ->first();

            if (!$aiConversation || !$aiConversation->ai_response) {
                return response()->json(['error' => 'No AI response found'], 404);
            }

            $account = $comment->socialAccount;

            // Publish the reply
            $service = new FacebookService();
            $published = $service->publishReply($comment, $aiConversation->ai_response, $account);

            if (!$published) {
                return response()->json(['error' => 'Failed to publish reply'], 500);
            }

            // Update conversation
            $aiConversation->update([
                'response_status' => 'approved',
                'approved_by_user_id' => Auth::id(),
                'approved_at' => now(),
            ]);

            // Update comment
            $comment->update([
                'status' => 'replied',
            ]);

            Log::info('AI response approved and published for comment: ' . $comment->id);

            return response()->json([
                'message' => 'Response published successfully',
                'status' => 'success',
            ]);

        } catch (\Exception $e) {
            Log::error('Error approving response: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to publish response',
            ], 500);
        }
    }

    /**
     * Reject AI response
     */
    public function rejectAIResponse(Request $request, SocialComment $comment)
    {
        if ($comment->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $validated = $request->validate([
            'reason' => 'string|max:500',
        ]);

        try {
            $aiConversation = AiConversation::where('social_comment_id', $comment->id)
                ->latest()
                ->first();

            if (!$aiConversation) {
                return response()->json(['error' => 'No conversation found'], 404);
            }

            $aiConversation->update([
                'response_status' => 'rejected',
                'rejection_reason' => $validated['reason'] ?? null,
                'rejected_by_user_id' => Auth::id(),
                'rejected_at' => now(),
            ]);

            Log::info('AI response rejected for comment: ' . $comment->id);

            return response()->json([
                'message' => 'Response rejected',
                'status' => 'success',
            ]);

        } catch (\Exception $e) {
            Log::error('Error rejecting response: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to reject response'], 500);
        }
    }

    /**
     * Mark comment as responded
     */
    public function markAsResponded(Request $request, SocialComment $comment)
    {
        if ($comment->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        try {
            $comment->update([
                'status' => 'replied',
                'responded_at' => now(),
            ]);

            Log::info('Comment marked as responded: ' . $comment->id);

            return response()->json([
                'message' => 'Comment marked as responded',
                'status' => 'success',
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update'], 500);
        }
    }

    /**
     * Mark comment as reviewed
     */
    public function markAsReviewed(Request $request, SocialComment $comment)
    {
        if ($comment->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $comment->update([
            'status' => 'reviewed',
        ]);

        return response()->json(['message' => 'Comment marked as reviewed']);
    }
}