<?php

namespace App\Http\Controllers;

use App\Jobs\SyncYoutubeComments;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Services\YoutubeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YoutubeController extends Controller
{
    public function login()
    {
        $clientId = env('YOUTUBE_CLIENT_ID');
        $redirectUri = env('YOUTUBE_REDIRECT_URI');

        if (!$clientId || !$redirectUri) {
            return back()->with('error', 'YouTube configuration missing.');
        }

        $state = bin2hex(random_bytes(16));
        session(['youtube_oauth_state' => $state]);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube.force-ssl',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return redirect("https://accounts.google.com/o/oauth2/v2/auth?{$params}");
    }

    public function callback(Request $request, YoutubeService $youtube)
    {
        if ($request->error) {
            return redirect('/settings/social-accounts')
                ->with('error', 'YouTube error: ' . $request->error);
        }

        if (!$request->state || $request->state !== session('youtube_oauth_state')) {
            return redirect('/settings/social-accounts')
                ->with('error', 'Invalid YouTube OAuth state.');
        }

        if (!$request->code) {
            return redirect('/settings/social-accounts')
                ->with('error', 'No authorization code received from YouTube.');
        }

        try {
            $tokenData = $youtube->exchangeCodeForToken($request->code);

            if (empty($tokenData['access_token'])) {
                throw new \Exception('No YouTube access token received.');
            }

            $channel = $youtube->getMyChannel($tokenData['access_token']);

            if (!$channel) {
                throw new \Exception('No YouTube channel found for this Google account.');
            }

            SocialAccount::updateOrCreate(
                [
                    'organization_id' => Auth::user()->organization_id,
                    'platform' => 'youtube',
                    'platform_account_id' => $channel['id'],
                ],
                [
                    'user_id' => Auth::id(),
                    'platform_account_name' => $channel['snippet']['title'] ?? 'YouTube Channel',
                    'platform_account_handle' => $channel['snippet']['customUrl'] ?? null,
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                    'metadata' => [
                        'channel' => $channel,
                    ],
                    'status' => 'connected',
                    'is_active' => true,
                ]
            );

            session()->forget('youtube_oauth_state');

            return redirect('/settings/social-accounts')
                ->with('success', 'YouTube channel connected successfully.');

        } catch (\Exception $e) {
            Log::error('YouTube callback error: ' . $e->getMessage());

            return redirect('/settings/social-accounts')
                ->with('error', $e->getMessage());
        }
    }

    public function sync(Request $request, SocialAccount $account)
    {
        if ($account->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($account->platform !== 'youtube') {
            return response()->json(['error' => 'Invalid YouTube account.'], 422);
        }

        SyncYoutubeComments::dispatch($account);

        return response()->json([
            'message' => 'YouTube sync started.',
            'status' => 'processing',
        ]);
    }

    public function reply(Request $request, SocialComment $comment, YoutubeService $youtube)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        if ($comment->socialAccount->organization_id !== Auth::user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $response = $youtube->replyToComment(
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