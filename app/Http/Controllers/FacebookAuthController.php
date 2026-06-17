<?php

// app/Http/Controllers/FacebookAuthController.php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookAuthController extends Controller
{
    /**
     * Redirect to Facebook login
     */
    public function login()
    {
        $appId = env('FACEBOOK_APP_ID');
        $redirectUri = env('FACEBOOK_REDIRECT_URI');
        
        if (!$appId || !$redirectUri) {
            return back()->with('error', 'Facebook configuration missing. Add FACEBOOK_APP_ID and FACEBOOK_REDIRECT_URI to .env');
        }

        $scope = 'email,pages_manage_engagement,public_profile,pages_read_user_content,pages_read_engagement,instagram_basic,pages_manage_metadata,pages_show_list,business_management,instagram_manage_comments';
        $state = bin2hex(random_bytes(16));
        
        // Store state in session for validation
        session(['facebook_oauth_state' => $state]);

        $url = "https://www.facebook.com/v18.0/dialog/oauth?" .
               "client_id={$appId}" .
               "&redirect_uri=" . urlencode($redirectUri) .
               "&scope={$scope}" .
               "&state={$state}" .
               "&display=popup";

        return redirect($url);
    }

    /**
     * Handle Facebook OAuth callback
     */
    public function callback(Request $request)
    {
        $code = $request->code;
        $state = $request->state;
        $error = $request->error;
        $errorDescription = $request->error_description;

        // Check for errors
        if ($error) {
            Log::warning('Facebook OAuth Error: ' . $error, ['description' => $errorDescription]);
            return redirect('/settings/social-accounts')
                ->with('error', 'Facebook error: ' . ($errorDescription ?? $error));
        }

        // Validate state
        if (!$state || $state !== session('facebook_oauth_state')) {
            Log::warning('Facebook OAuth: Invalid state');
            return redirect('/settings/social-accounts')
                ->with('error', 'Invalid OAuth state. Please try again.');
        }

        // Validate code
        if (!$code) {
            return redirect('/settings/social-accounts')
                ->with('error', 'No authorization code received. Please try again.');
        }

        try {
            // Exchange code for access token
            $tokenData = $this->getAccessToken($code);

            if (isset($tokenData['error'])) {
                Log::error('Facebook token error: ' . json_encode($tokenData['error']));
                return redirect('/settings/social-accounts')
                    ->with('error', 'Failed to get access token: ' . $tokenData['error']['message']);
            }

            $accessToken = $tokenData['access_token'] ?? null;
            if (!$accessToken) {
                throw new \Exception('No access token in response');
            }

            // Get user's pages
            $pages = $this->getUserPages($accessToken);

            if (empty($pages)) {
                return redirect('/settings/social-accounts')
                    ->with('error', 'No Facebook pages found. Make sure you have admin access to at least one page.');
            }

            // Save each page as a social account
            $savedCount = 0;
            foreach ($pages as $page) {
                $existing = SocialAccount::where('platform_account_id', $page['id'])->first();

                if (!$existing) {
                    SocialAccount::create([
                        'organization_id' => Auth::user()->organization_id,
                        'user_id' => Auth::id(),
                        'platform' => 'facebook',
                        'platform_account_id' => $page['id'],
                        'platform_account_name' => $page['name'],
                        'platform_account_handle' => $page['name'],
                        'access_token' => $page['access_token'],
                        'status' => 'connected',
                        'is_active' => true,
                        'auto_reply_started_at' => now(),
                    ]);
                    $savedCount++;
                } else {
                    // Update existing account
                    $existing->update([
                        'access_token' => $page['access_token'],
                        'status' => 'connected',
                        'is_active' => true,
                        'auto_reply_started_at' => now(),
                    ]);
                    $savedCount++;
                }
            }

            // Clear session
            session()->forget('facebook_oauth_state');

            return redirect('/settings/social-accounts')
                ->with('success', "Successfully connected {$savedCount} Facebook page(s)!");
        } catch (\Exception $e) {
            Log::error('Facebook callback error: ' . $e->getMessage());
            return redirect('/settings/social-accounts')
                ->with('error', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Exchange authorization code for access token
     */
    private function getAccessToken(string $code): array
    {
        $appId = env('FACEBOOK_APP_ID');
        $appSecret = env('FACEBOOK_APP_SECRET');
        $redirectUri = env('FACEBOOK_REDIRECT_URI');
        $version = env('FACEBOOK_GRAPH_VERSION', 'v18.0');

        $url = "https://graph.facebook.com/{$version}/oauth/access_token?" .
               "client_id={$appId}" .
               "&client_secret={$appSecret}" .
               "&redirect_uri=" . urlencode($redirectUri) .
               "&code={$code}";

        $response = Http::get($url);
        return $response->json() ?? [];
    }

    /**
     * Get user's pages
     */
    private function getUserPages(string $accessToken): array
    {
        $version = env('FACEBOOK_GRAPH_VERSION', 'v18.0');
        
        $url = "https://graph.facebook.com/{$version}/me/accounts?" .
               "fields=id,name,picture,access_token&" .
               "access_token={$accessToken}";

        $response = Http::get($url);
        $data = $response->json() ?? [];

        return $data['data'] ?? [];
    }

    /**
     * Disconnect a Facebook account
     */
    public function disconnect(Request $request, SocialAccount $account)
    {
        $this->authorize('delete', $account);

        $account->update([
            'status' => 'disconnected',
            'is_active' => false,
        ]);

        return response()->json(['message' => 'Account disconnected']);
    }

    /**
     * Sync comments from a Facebook account
     */
    public function sync(Request $request, SocialAccount $account)
    {
        $this->authorize('update', $account);

        // Dispatch sync job
        \App\Jobs\SyncFacebookComments::dispatch($account);

        return response()->json([
            'message' => 'Sync started. Comments will be updated shortly.',
        ]);
    }

    /**
     * Test Facebook connection
     */
    public function test(Request $request)
    {
        $token = $request->token;

        if (!$token) {
            return response()->json(['error' => 'Token required'], 400);
        }

        try {
            $version = env('FACEBOOK_GRAPH_VERSION', 'v18.0');
            $url = "https://graph.facebook.com/{$version}/me?fields=id,name,email&access_token={$token}";
            
            $response = Http::get($url);
            $data = $response->json();

            if (isset($data['error'])) {
                return response()->json(['error' => $data['error']['message']], 400);
            }

            return response()->json([
                'success' => true,
                'user' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}