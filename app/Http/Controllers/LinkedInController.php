<?php

namespace App\Http\Controllers;

use App\Jobs\SyncLinkedInComments;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Services\LinkedInService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LinkedInController extends Controller
{
    public function login(LinkedInService $linkedin)
    {
        $state = bin2hex(random_bytes(16));

        session([
            'linkedin_oauth_state' => $state,
        ]);

        return redirect($linkedin->getAuthUrl($state));
    }

    public function callback(Request $request, LinkedInService $linkedin)
    {
        if ($request->error) {
            return redirect('/settings/social-accounts')
                ->with('error', 'LinkedIn error: ' . $request->error);
        }

        if (!$request->state || $request->state !== session('linkedin_oauth_state')) {
            return redirect('/settings/social-accounts')
                ->with('error', 'Invalid LinkedIn OAuth state.');
        }

        if (!$request->code) {
            return redirect('/settings/social-accounts')
                ->with('error', 'No authorization code received from LinkedIn.');
        }

        try {
            $tokenData = $linkedin->exchangeCodeForToken($request->code);

            $accessToken = $tokenData['access_token'] ?? null;

            if (!$accessToken) {
                throw new \Exception('No LinkedIn access token received.');
            }

            $profile = $linkedin->getCurrentUser($accessToken);
            $organizations = $linkedin->getManagedOrganizations($accessToken);

            if (empty($organizations)) {
                throw new \Exception('No LinkedIn organization page found. Make sure this LinkedIn user is an admin of a company page.');
            }

            $savedCount = 0;

            foreach ($organizations as $organization) {
                SocialAccount::updateOrCreate(
                    [
                        'organization_id' => Auth::user()->organization_id,
                        'platform' => 'linkedin',
                        'platform_account_id' => $organization['id'],
                    ],
                    [
                        'user_id' => Auth::id(),
                        'platform_account_name' => $organization['name'] ?? 'LinkedIn Organization',
                        'platform_account_handle' => $organization['vanityName'] ?? null,
                        'access_token' => $accessToken,
                        'refresh_token' => $tokenData['refresh_token'] ?? null,
                        'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                        'metadata' => [
                            'profile' => $profile,
                            'organization' => $organization,
                        ],
                        'status' => 'connected',
                        'is_active' => true,
                    ]
                );

                $savedCount++;
            }

            session()->forget('linkedin_oauth_state');

            return redirect('/settings/social-accounts')
                ->with('success', "LinkedIn connected successfully. {$savedCount} organization(s) saved.");

        } catch (\Exception $e) {
            Log::error('LinkedIn callback error: ' . $e->getMessage());

            return redirect('/settings/social-accounts')
                ->with('error', $e->getMessage());
        }
    }

    public function sync(Request $request, SocialAccount $account)
    {
        if ($account->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($account->platform !== 'linkedin') {
            return response()->json(['error' => 'Invalid LinkedIn account.'], 422);
        }

        SyncLinkedInComments::dispatch($account);

        return response()->json([
            'message' => 'LinkedIn sync started.',
            'status' => 'processing',
        ]);
    }

    public function reply(Request $request, SocialComment $comment, LinkedInService $linkedin)
    {
        $request->validate([
            'message' => 'required|string|max:1250',
        ]);

        if ($comment->socialAccount->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $response = $linkedin->replyToComment(
            $comment->socialAccount,
            $comment->platform_comment_id,
            $request->message
        );

        return response()->json([
            'message' => 'LinkedIn reply posted successfully.',
            'data' => $response,
        ]);
    }
}