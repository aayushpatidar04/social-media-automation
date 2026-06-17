<?php

namespace App\Http\Controllers;

use App\Jobs\SyncTwitterComments;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Services\TwitterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TwitterController extends Controller
{
    public function login(TwitterService $twitter)
    {
        $state = bin2hex(random_bytes(16));
        $codeVerifier = $twitter->generateCodeVerifier();
        $codeChallenge = $twitter->generateCodeChallenge($codeVerifier);

        session([
            'twitter_oauth_state' => $state,
            'twitter_code_verifier' => $codeVerifier,
        ]);

        return redirect($twitter->getAuthUrl($state, $codeChallenge));
    }

    public function callback(Request $request, TwitterService $twitter)
    {
        if ($request->error) {
            return redirect('/settings/social-accounts')
                ->with('error', 'X error: ' . $request->error);
        }

        if (!$request->state || $request->state !== session('twitter_oauth_state')) {
            return redirect('/settings/social-accounts')
                ->with('error', 'Invalid X OAuth state.');
        }

        if (!$request->code) {
            return redirect('/settings/social-accounts')
                ->with('error', 'No authorization code received from X.');
        }

        try {
            $tokenData = $twitter->exchangeCodeForToken(
                $request->code,
                session('twitter_code_verifier')
            );

            $accessToken = $tokenData['access_token'] ?? null;

            if (!$accessToken) {
                throw new \Exception('No X access token received.');
            }

            $user = $twitter->getCurrentUser($accessToken);

            SocialAccount::updateOrCreate(
                [
                    'organization_id' => Auth::user()->organization_id,
                    'platform' => 'twitter',
                    'platform_account_id' => $user['id'],
                ],
                [
                    'user_id' => Auth::id(),
                    'platform_account_name' => $user['name'] ?? 'X Account',
                    'platform_account_handle' => $user['username'] ?? null,
                    'access_token' => $accessToken,
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 7200),
                    'metadata' => [
                        'user' => $user,
                    ],
                    'status' => 'connected',
                    'is_active' => true,
                    'auto_reply_started_at' => now(),
                ]
            );

            session()->forget([
                'twitter_oauth_state',
                'twitter_code_verifier',
            ]);

            return redirect('/settings/social-accounts')
                ->with('success', 'X account connected successfully.');

        } catch (\Exception $e) {
            Log::error('X callback error: ' . $e->getMessage());

            return redirect('/settings/social-accounts')
                ->with('error', $e->getMessage());
        }
    }

    public function sync(Request $request, SocialAccount $account)
    {
        if ($account->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($account->platform !== 'twitter') {
            return response()->json(['error' => 'Invalid X account.'], 422);
        }

        SyncTwitterComments::dispatch($account);

        return response()->json([
            'message' => 'X sync started.',
            'status' => 'processing',
        ]);
    }

    public function reply(Request $request, SocialComment $comment, TwitterService $twitter)
    {
        $request->validate([
            'message' => 'required|string|max:280',
        ]);

        if ($comment->socialAccount->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $response = $twitter->replyToTweet(
            $comment->socialAccount,
            $comment->platform_comment_id,
            $request->message
        );

        return response()->json([
            'message' => 'Reply posted successfully.',
            'data' => $response,
        ]);
    }
}