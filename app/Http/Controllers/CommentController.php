<?php

namespace App\Http\Controllers;

use App\Models\SocialComment;
use App\Models\AiConversation;
use App\Services\FacebookService;
use App\Services\InstagramService;
use App\Services\LinkedInService;
use App\Services\RAGService;
use App\Services\TwitterService;
use App\Services\YoutubeService;
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
            ->with([
                'socialAccount',
                'socialPost',
                'aiConversation',
                'threadReplies.aiConversation',
                'threadReplies.socialAccount',
            ])
            ->where('direction', 'inbound')
            ->where(function ($q) {
                $q->whereColumn('social_comments.id', 'root_id')
                    ->orWhereNull('parent_id');
            });

        if ($request->filled('status')) {
            $query->where('social_comments.status', $request->status);
        }

        if ($request->filled('sentiment')) {
            $query->where('social_comments.sentiment', $request->sentiment);
        }

        if ($request->filled('intent')) {
            $query->where('social_comments.intent', $request->intent);
        }

        if ($request->filled('platform')) {
            $query->where('social_comments.platform', $request->platform);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('social_comments.content', 'like', "%{$search}%")
                    ->orWhere('social_comments.author_name', 'like', "%{$search}%")
                    ->orWhereHas('threadReplies', function ($replyQuery) use ($search) {
                        $replyQuery->where('social_comments.content', 'like', "%{$search}%")
                            ->orWhere('social_comments.author_name', 'like', "%{$search}%");
                    });
            });
        }

        $comments = $query
            ->latest('social_comments.commented_at')
            ->paginate(20);

        return Inertia::render('Inbox', [
            'comments' => $comments,
            'filters' => $request->only([
                'status',
                'sentiment',
                'intent',
                'platform',
                'search',
            ]),
        ]);
    }

    /**
     * Filter comments (AJAX)
     */
    public function filter(Request $request)
    {
        $organization = Auth::user()->organization;

        $query = $organization->socialComments()
            ->with(['socialAccount', 'socialPost', 'aiConversation'])
            ->latest('social_comments.commented_at');

        // Apply all filters — prefix with table name to avoid ambiguity
        if ($request->status) {
            $query->where('social_comments.status', $request->status);
        }
        if ($request->sentiment) {
            $query->where('social_comments.sentiment', $request->sentiment);
        }
        if ($request->intent) {
            $query->where('social_comments.intent', $request->intent);
        }
        if ($request->platform) {
            $query->where('social_comments.platform', $request->platform);
        }
        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('social_comments.content', 'like', "%{$search}%")
                    ->orWhere('social_comments.author_name', 'like', "%{$search}%");
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
        if ($comment->socialAccount->organization_id !== Auth::user()->organization_id) {
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
     * Get AI conversation for a comment
     */
    public function getAiConversation(SocialComment $comment)
    {
        if ($comment->socialAccount->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $conversation = AiConversation::where('social_comment_id', $comment->id)
                ->latest()
                ->first();

            if (!$conversation) {
                return response()->json([
                    'has_ai_response' => false,
                    'ai_response' => null,
                    'confidence' => 0,
                    'model_used' => null,
                ]);
            }

            return response()->json([
                'has_ai_response' => !empty($conversation->ai_response),
                'ai_response' => $conversation->ai_response,
                'confidence' => $conversation->confidence ?? 0.8,
                'model_used' => $conversation->model_used ?? 'ollama_gemma2',
                'created_at' => $conversation->created_at,
                'status' => $conversation->response_status,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching AI conversation: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch AI response'], 500);
        }
    }

    /**
     * Send manual reply to a comment
     */
    public function sendReply(Request $request, SocialComment $comment)
    {
        if ($comment->socialAccount->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:500',
            'is_ai_response' => 'boolean',
        ]);

        try {
            $account = $comment->socialAccount;

            if (!$account) {
                return response()->json(['error' => 'Account not found'], 404);
            }

            // Publish to platform
            $published = $this->publishReply($comment, $validated['message']);

            if (!$published) {
                return response()->json(['error' => 'Failed to publish reply'], 500);
            }

            // Update or create AI conversation record
            $aiConversation = AiConversation::where('social_comment_id', $comment->id)
                ->latest()
                ->first();

            if ($aiConversation) {
                $aiConversation->update([
                    'response_status' => 'approved',
                    'approved_by_user_id' => Auth::id(),
                    'approved_at' => now(),
                    'is_ai_response' => $validated['is_ai_response'] ?? false,
                ]);
                
            } else {
                $aiConversation = AiConversation::create([
                    'organization_id' => $comment->socialAccount->organization_id,
                    'social_comment_id' => $comment->id,
                    'user_id' => Auth::id(),
                    'ai_response' => $validated['message'],
                    'response_status' => 'approved',
                    'confidence' => 1.0,
                    'is_ai_response' => false,
                    'approved_by_user_id' => Auth::id(),
                    'approved_at' => now(),
                ]);
                
            }

            // Update comment status
            $comment->update([
                'status' => 'replied',
                'replied_at' => now(),
            ]);

            // Log activity
            \App\Models\ActivityLog::create([
                'organization_id' => $comment->socialAccount->organization_id,
                'user_id' => Auth::id(),
                'action' => 'comment_replied',
                'entity_type' => 'social_comment',
                'entity_id' => $comment->id,
                'data' => [
                    'platform' => $comment->platform,
                    'response_type' => $validated['is_ai_response'] ? 'ai' : 'manual',
                ],
            ]);

            return response()->json([
                'message' => 'Reply sent successfully',
                'status' => 'success',
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending reply: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to send reply: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve AI response and send it
     */
    public function approveAIResponse(Request $request, SocialComment $comment)
    {
        if ($comment->socialAccount->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        try {
            $aiConversation = AiConversation::where('social_comment_id', $comment->id)
                ->where('response_status', 'pending')
                ->latest()
                ->first();

            if (!$aiConversation || !$aiConversation->ai_response) {
                return response()->json(['error' => 'No AI response to approve'], 404);
            }

            $account = $comment->socialAccount;

            if (!$account) {
                return response()->json(['error' => 'Account not found'], 404);
            }

            $published = $this->publishReply($comment, $aiConversation->ai_response);

            if (!$published) {
                return response()->json(['error' => 'Failed to publish response'], 500);
            }

            $aiConversation->update([
                'response_status' => 'approved',
                'approved_by_user_id' => Auth::id(),
                'approved_at' => now(),
            ]);

            $comment->update([
                'status' => 'replied',
                'replied_at' => now(),
            ]);

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
        if ($comment->socialAccount->organization_id !== Auth::user()->organization_id) {
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
        if ($comment->socialAccount->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        try {
            $comment->update([
                'status' => 'replied',
                'replied_at' => now(),
            ]);

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
        if ($comment->socialAccount->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $comment->update([
            'status' => 'reviewed',
        ]);

        return response()->json(['message' => 'Comment marked as reviewed']);
    }

    /**
     * Publish reply to the appropriate platform
     */
    private function publishReply(SocialComment $comment, string $message)
    {
        try {
            $account = $comment->socialAccount;

            if (!$account) {
                return false;
            }

            return match ($comment->platform) {
                'facebook' => (new FacebookService())->publishReply($comment, $message, $account),
                'instagram' => (new InstagramService())->publishReply($comment, $message, $account),
                'youtube' => (new YoutubeService())->publishReply($comment, $message, $account),
                'twitter' => (new TwitterService())->publishReply($comment, $message, $account),
                'linkedin' => (new LinkedInService())->publishReply($comment, $message, $account),
                default => false,
            };

        } catch (\Exception $e) {
            Log::error('Error publishing reply: ' . $e->getMessage());
            return false;
        }
    }
}
