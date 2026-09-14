<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessYoutubeWebhook;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class YoutubeWebhookController extends Controller
{
    /**
     * YouTube PubSubHubbub hub will POST here when a new comment is posted.
     * It sends an Atom XML feed in the request body.
     */
    public function handle(Request $request)
    {
        // WebSub verification challenge — hub sends this as GET with hub.challenge
        if ($request->isMethod('get') || $request->has('hub_challenge')) {
            $challenge = $request->input('hub_challenge');
            Log::info('YouTube PubSubHubbub verification challenge received', [
                'challenge' => $challenge,
                'mode' => $request->input('hub_mode'),
            ]);
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        // YouTube sends the Atom feed as raw XML in the request body (POST)
        $body = $request->getContent();

        if (empty($body)) {
            return response('Empty body', 400);
        }

        try {
            $xml = simplexml_load_string($body);
            if (!$xml) {
                Log::warning('YouTube webhook: invalid XML received');
                return response('Invalid XML', 400);
            }

            $namespaces = $xml->getNamespaces(true);
            $atomNs = $namespaces['atom'] ?? 'http://www.w3.org/2005/Atom';
            $ytNs = $namespaces['yt'] ?? 'http://www.youtube.com/xml/schemas/2015';

            // Extract the entry (new comment notification)
            $entry = $xml->children($atomNs)->entry;
            if (!$entry) {
                Log::info('YouTube webhook: no entry in feed');
                return response('No entry', 200);
            }

            // Get video ID from the link
            $links = $entry->children($atomNs)->link;
            $videoId = null;

            foreach ($links as $link) {
                $href = (string) $link->attributes()->href;
                if (preg_match('/v=([a-zA-Z0-9_-]+)/', $href, $matches)) {
                    $videoId = $matches[1];
                    break;
                }
            }

            if (!$videoId) {
                Log::warning('YouTube webhook: could not extract video ID');
                return response('No video ID', 400);
            }

            Log::info('YouTube webhook: new comment notification', [
                'video_id' => $videoId,
            ]);

            // Dispatch async job to fetch and process the new comment
            ProcessYoutubeWebhook::dispatch((string) $body, $videoId);

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('YouTube webhook processing error', [
                'error' => $e->getMessage(),
                'body_preview' => substr($body, 0, 500),
            ]);

            return response('Error', 500);
        }
    }
}
