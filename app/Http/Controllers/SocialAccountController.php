<?php

// app/Http/Controllers/SocialAccountController.php - COMPLETE

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Jobs\SyncFacebookComments;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SocialAccountController extends Controller
{
    public function index()
    {
        $organization = Auth::user()->organization;
        
        $accounts = $organization->socialAccounts()
            ->with('user')
            ->latest()
            ->get();

        return Inertia::render('Settings/SocialAccounts', [
            'accounts' => $accounts,
            'facebook_login_url' => route('auth.facebook'),
            'available_platforms' => ['facebook', 'instagram', 'youtube', 'twitter', 'linkedin'],
        ]);
    }

    /**
     * Sync comments from a social account
     */
    public function sync(Request $request, SocialAccount $account)
    {
        // Check authorization
        if ($account->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            Log::info('Starting sync for account: ' . $account->platform_account_name);

            // Dispatch sync job
            SyncFacebookComments::dispatch($account);

            return response()->json([
                'message' => 'Sync started! Comments will be updated shortly.',
                'status' => 'processing',
            ]);
        } catch (\Exception $e) {
            Log::error('Sync error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to start sync: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Disconnect a social account
     */
    public function disconnect(Request $request, SocialAccount $account)
    {
        // Check authorization
        if ($account->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            // Deactivate the account
            $account->update([
                'status' => 'disconnected',
                'is_active' => false,
            ]);

            Log::info('Account disconnected: ' . $account->id);

            return response()->json([
                'message' => 'Account disconnected successfully',
                'status' => 'disconnected',
            ]);
        } catch (\Exception $e) {
            Log::error('Disconnect error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to disconnect: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reconnect a disconnected account
     */
    public function reconnect(Request $request, SocialAccount $account)
    {
        if ($account->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $account->update([
                'status' => 'connected',
                'is_active' => true,
            ]);

            return response()->json([
                'message' => 'Account reconnected successfully',
                'status' => 'connected',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to reconnect'], 500);
        }
    }

    /**
     * Test account connection
     */
    public function test(Request $request, SocialAccount $account)
    {
        if ($account->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            // Try to fetch one post to verify token
            $version = env('FACEBOOK_GRAPH_VERSION', 'v18.0');
            $url = "https://graph.facebook.com/{$version}/" . $account->platform_account_id . "/posts?limit=1&access_token=" . $account->access_token;
            
            $response = @file_get_contents($url);
            $data = json_decode($response, true);

            if (isset($data['error'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $data['error']['message'],
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Account is connected and working',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}