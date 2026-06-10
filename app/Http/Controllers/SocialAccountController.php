<?php

// app/Http/Controllers/SocialAccountController.php
 
namespace App\Http\Controllers;
 
use App\Models\SocialAccount;
use App\Models\Organization;
use App\Services\FacebookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Redirect;
 
class SocialAccountController extends Controller
{
    public function index(): Response
    {
        $organization = Auth::user()->organization;
        
        $accounts = $organization->socialAccounts()
            ->with('user')
            ->get();
 
        return Inertia::render('Settings/SocialAccounts', [
            'accounts' => $accounts,
            'facebook_login_url' => FacebookService::getLoginUrl(),
            'available_platforms' => ['facebook', 'instagram', 'youtube', 'twitter', 'linkedin'],
        ]);
    }
 
    public function disconnect(SocialAccount $account)
    {
        $this->authorize('delete', $account);
 
        $account->update(['status' => 'disconnected', 'is_active' => false]);
 
        return response()->json(['message' => 'Account disconnected']);
    }
 
    public function refresh(SocialAccount $account)
    {
        $this->authorize('update', $account);
 
        // Dispatch sync job
        \App\Jobs\SyncFacebookComments::dispatch($account);
 
        return response()->json(['message' => 'Sync started']);
    }
 
    public function syncNow(SocialAccount $account)
    {
        $this->authorize('update', $account);
 
        \App\Jobs\SyncFacebookComments::dispatch($account);
 
        return response()->json([
            'message' => 'Sync job queued',
            'job_id' => uniqid(),
        ]);
    }
}
