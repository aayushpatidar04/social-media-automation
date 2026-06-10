<?php

// app/Http/Controllers/CommentController.php
 
namespace App\Http\Controllers;
 
use App\Models\SocialComment;
use App\Models\Organization;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
 
class CommentController extends Controller
{
    public function inbox(): Response
    {
        $organization = Auth::user()->organization;
        
        $comments = $organization->socialComments()
            ->with(['socialPost', 'socialAccount', 'aiConversation'])
            ->latest('commented_at')
            ->paginate(20);
 
        return Inertia::render('Inbox', [
            'comments' => $comments,
            'filters' => request()->only(['status', 'sentiment', 'intent', 'platform']),
            'pusher_key' => env('PUSHER_APP_KEY'),
            'pusher_cluster' => env('PUSHER_APP_CLUSTER'),
        ]);
    }
 
    public function show(SocialComment $comment): Response
    {
        $this->authorize('view', $comment);
 
        return Inertia::render('CommentDetail', [
            'comment' => $comment->load(['socialPost', 'socialAccount', 'aiConversation.reviewedBy']),
            'relatedComments' => $comment->socialPost->socialComments()
                ->where('id', '!=', $comment->id)
                ->limit(5)
                ->get(),
        ]);
    }
 
    public function filter(Request $request)
    {
        $organization = Auth::user()->organization;
        
        $query = $organization->socialComments()
            ->with(['socialPost', 'socialAccount', 'aiConversation']);
 
        // Apply filters
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
 
        if ($request->has('sentiment') && $request->sentiment !== 'all') {
            $query->where('sentiment', $request->sentiment);
        }
 
        if ($request->has('intent') && $request->intent !== 'all') {
            $query->where('intent', $request->intent);
        }
 
        if ($request->has('platform') && $request->platform !== 'all') {
            $query->whereHas('socialAccount', function ($q) {
                $q->where('platform', request()->platform);
            });
        }
 
        if ($request->has('is_lead') && $request->is_lead) {
            $query->where('is_lead', true);
        }
 
        if ($request->has('search')) {
            $query->where('content', 'like', '%' . $request->search . '%')
                  ->orWhere('author_name', 'like', '%' . $request->search . '%');
        }
 
        $comments = $query->latest('commented_at')
            ->paginate(20);
 
        return response()->json($comments);
    }
 
    public function approveAIResponse(SocialComment $comment)
    {
        $this->authorize('update', $comment);
 
        $aiConversation = $comment->aiConversation;
        if (!$aiConversation) {
            return response()->json(['error' => 'No AI response found'], 404);
        }
 
        $aiConversation->update([
            'response_status' => 'approved',
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
        ]);
 
        // Dispatch job to publish reply
        \App\Jobs\PublishAutoReply::dispatch($comment);
 
        return response()->json(['message' => 'Response approved and queued for publishing']);
    }
 
    public function rejectAIResponse(SocialComment $comment, Request $request)
    {
        $this->authorize('update', $comment);
 
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);
 
        $aiConversation = $comment->aiConversation;
        $aiConversation->update([
            'response_status' => 'rejected',
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
            'review_reason' => $request->reason,
        ]);
 
        return response()->json(['message' => 'Response rejected']);
    }
 
    public function markAsResponded(SocialComment $comment)
    {
        $this->authorize('update', $comment);
 
        $comment->update(['status' => 'replied']);
 
        // Broadcast update
        $analytics = new AnalyticsService($comment->socialAccount->organization);
        $analytics->broadcastMetricUpdate('summary', $analytics->getSummaryMetrics());
 
        return response()->json(['message' => 'Marked as responded']);
    }
}
