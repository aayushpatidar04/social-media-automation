<?php

// app/Http/Controllers/SocialAccountController.php - COMPLETE

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Jobs\SyncFacebookComments;
use App\Jobs\SyncInstagramComments;
use App\Jobs\SyncYoutubeComments;
use App\Jobs\SyncTwitterComments;
use App\Jobs\SyncLinkedInComments;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
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
            Log::info('Starting sync for account: ' . $account->platform_account_name . ' (' . $account->platform . ')');

            // Dispatch platform-specific sync job
            match ($account->platform) {
                'facebook' => SyncFacebookComments::dispatch($account),
                'instagram' => SyncInstagramComments::dispatch($account),
                'youtube' => SyncYoutubeComments::dispatch($account),
                'twitter' => SyncTwitterComments::dispatch($account),
                'linkedin' => SyncLinkedInComments::dispatch($account),
                default => Log::warning('No sync job for platform: ' . $account->platform),
            };

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
            $result = match ($account->platform) {
                'facebook', 'instagram' => $this->testFacebookConnection($account),
                'youtube' => $this->testYoutubeConnection($account),
                'twitter' => $this->testTwitterConnection($account),
                'linkedin' => $this->testLinkedInConnection($account),
                default => ['status' => 'error', 'message' => 'Unknown platform'],
            };

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function testFacebookConnection(SocialAccount $account): array
    {
        $version = env('FACEBOOK_GRAPH_VERSION', 'v25.0');
        $url = "https://graph.facebook.com/{$version}/" . $account->platform_account_id . "/posts?limit=1&access_token=" . $account->access_token;

        $response = Http::get($url);
        $data = $response->json();

        if (isset($data['error'])) {
            return [
                'status' => 'error',
                'message' => $data['error']['message'],
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Account is connected and working',
        ];
    }

    private function testYoutubeConnection(SocialAccount $account): array
    {
        $response = Http::withToken($account->access_token)
            ->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet',
                'mine' => 'true',
            ]);

        $data = $response->json();

        if (!$response->successful() || isset($data['error'])) {
            return [
                'status' => 'error',
                'message' => $data['error']['message'] ?? 'YouTube connection failed',
            ];
        }

        return [
            'status' => 'success',
            'message' => 'YouTube channel is connected',
        ];
    }

    private function testTwitterConnection(SocialAccount $account): array
    {
        $response = Http::withToken($account->access_token)
            ->get('https://api.x.com/2/users/me', [
                'user.fields' => 'id,name,username',
            ]);

        $data = $response->json();

        if (!$response->successful() || isset($data['detail'])) {
            return [
                'status' => 'error',
                'message' => $data['detail'] ?? 'X connection failed',
            ];
        }

        return [
            'status' => 'success',
            'message' => 'X account is connected',
        ];
    }

    private function testLinkedInConnection(SocialAccount $account): array
    {
        $response = Http::withToken($account->access_token)
            ->withHeaders([
                'LinkedIn-Version' => '202405',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->get('https://api.linkedin.com/v2/userinfo');

        $data = $response->json();

        if (!$response->successful() || isset($data['message'])) {
            return [
                'status' => 'error',
                'message' => $data['message'] ?? 'LinkedIn connection failed',
            ];
        }

        return [
            'status' => 'success',
            'message' => 'LinkedIn account is connected',
        ];
    }
}
