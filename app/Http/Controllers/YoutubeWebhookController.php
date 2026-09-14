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
        // YouTube sends the Atom feed as raw XML in the request body
        $body = $request->getContent();

        if (empty($body)) {
            return response('Empty body', 400);
        }

        try {
            // Parse the XML to extract video and comment info
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
                Log::info('YouTube webhook: no entry in feed (likely a challenge verification)');
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
                Log::warning('YouTube webhook: could not extract video ID', [
                    'links' => collect((array) $links)->map(fn($l) => (string) $l->attributes()->href)->toArray(),
                ]);
                return response('No video ID', 400);
            }

            Log::info('YouTube webhook: new comment notification', [
                'video_id' => $videoId,
                'entry_id' => (string) $entry->children($atomNs)->id,
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

    /**
     * Handle PubSubHubbub subscription verification challenge.
     * YouTube hub sends this when we first subscribe to a video's feed.
     */
    public function verify(Request $request)
    {
        $mode = $request->input('hub_mode');
        $topic = $request->input('hub_topic');
        $challenge = $request->input('hub_challenge');
        $leaseSeconds = $request->input('hub_lease_seconds');

        Log::info('YouTube PubSubHubbub verification', [
            'mode' => $mode,
            'topic' => $topic,
            'lease_seconds' => $leaseSeconds,
        ]);

        if ($mode === 'subscribe') {
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Verification failed', 403);
    }
}
