<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Auth;

class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = Auth::user()->organization;

        $query = $organization->leads()
            ->with(['socialComment.socialAccount', 'assignedTo']);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('lead_status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('lead_type', $request->type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('author_name', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        $leads = $query->latest('created_at')->paginate(20);

        // Stats for the filter sidebar
        $allLeads = $organization->leads();
        $stats = [
            'new' => (clone $allLeads)->where('lead_status', 'new')->count(),
            'contacted' => (clone $allLeads)->where('lead_status', 'contacted')->count(),
            'qualified' => (clone $allLeads)->where('lead_status', 'qualified')->count(),
            'converted' => (clone $allLeads)->where('lead_status', 'converted')->count(),
            'lost' => (clone $allLeads)->where('lead_status', 'lost')->count(),
        ];

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'stats' => $stats,
            'filters' => $request->only(['status', 'type', 'search']),
        ]);
    }

    public function show(Lead $lead): Response
    {
        $this->authorize('view', $lead);

        return Inertia::render('Leads/Show', [
            'lead' => $lead->load(['socialComment.socialAccount', 'assignedTo', 'organization']),
        ]);
    }

    public function assign(Lead $lead, Request $request)
    {
        $this->authorize('update', $lead);

        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $lead->update([
            'assigned_to_user_id' => $request->user_id,
            'assigned_at' => now(),
        ]);

        \App\Models\ActivityLog::create([
            'organization_id' => $lead->organization_id,
            'user_id' => Auth::id(),
            'action' => 'lead_assigned',
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'changes' => ['assigned_to' => $request->user_id],
        ]);

        return response()->json(['message' => 'Lead assigned successfully']);
    }

    public function updateStatus(Lead $lead, Request $request)
    {
        $this->authorize('update', $lead);

        $request->validate([
            'status' => 'required|in:new,contacted,qualified,converted,lost',
        ]);

        $lead->update([
            'lead_status' => $request->status,
        ]);

        \App\Models\ActivityLog::create([
            'organization_id' => $lead->organization_id,
            'user_id' => Auth::id(),
            'action' => 'lead_status_updated',
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'changes' => ['status' => $request->status],
        ]);

        return response()->json(['message' => 'Lead status updated']);
    }

    public function logContact(Lead $lead, Request $request)
    {
        $this->authorize('update', $lead);

        $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $lead->update([
            'notes' => ($lead->notes ?? '') . "\n\n[" . now()->format('Y-m-d H:i:s') . "] " . $request->notes,
            'last_contacted_at' => now(),
        ]);

        \App\Models\ActivityLog::create([
            'organization_id' => $lead->organization_id,
            'user_id' => Auth::id(),
            'action' => 'lead_contacted',
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
        ]);

        return response()->json(['message' => 'Contact logged']);
    }

    public function filter(Request $request)
    {
        $organization = Auth::user()->organization;

        $query = $organization->leads()
            ->with(['socialComment', 'assignedTo']);

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('lead_status', $request->status);
        }

        if ($request->has('type') && $request->type !== 'all') {
            $query->where('lead_type', $request->type);
        }

        if ($request->has('search')) {
            $query->where(function ($q) {
                $q->where('author_name', 'like', '%' . request()->search . '%')
                    ->orWhere('company_name', 'like', '%' . request()->search . '%')
                    ->orWhere('contact_email', 'like', '%' . request()->search . '%');
            });
        }

        $leads = $query->latest('created_at')->paginate(20);

        return response()->json($leads);
    }
}
