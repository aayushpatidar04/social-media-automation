<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessYoutubeWebhook;
use App\Models\SocialAccount;
use App\Services\YoutubeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class YoutubeWebhookController extends Controller
{
    public function handle(Request $request, YoutubeService $youtube)
    {
        // WebSub verification challenge — hub sends this as GET with hub.challenge
        if ($request->isMethod('get') || $request->has('hub_challenge')) {
            $challenge = $request->input('hub_challenge');
            Log::info('YouTube PubSubHubbub verification challenge received', [
                'challenge' => $challenge,
                'mode' => $request->input('hub_mode'),
                'topic' => $request->input('hub_topic'),
            ]);
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        // POST — actual notification
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

            $entry = $xml->children($atomNs)->entry;
            if (!$entry) {
                Log::info('YouTube webhook: no entry in feed');
                return response('No entry', 200);
            }

            // Detect feed type: video-level (comments) or channel-level (new uploads)
            $topic = $request->input('hub_topic') ?? '';
            $isChannelFeed = str_contains($topic, 'channel_id');

            // Extract video ID from link
            $videoId = null;
            $links = $entry->children($atomNs)->link;
            foreach ($links as $link) {
                $href = (string) $link->attributes()->href;
                if (preg_match('/v=([a-zA-Z0-9_-]+)/', $href, $matches)) {
                    $videoId = $matches[1];
                    break;
                }
            }

            if (!$videoId) {
                Log::warning('YouTube webhook: could not extract video ID', [
                    'topic' => $topic,
                ]);
                return response('No video ID', 200); // 200 so hub doesn't retry
            }

            Log::info('YouTube webhook: notification received', [
                'video_id' => $videoId,
                'feed_type' => $isChannelFeed ? 'channel' : 'video',
            ]);

            if ($isChannelFeed) {
                // New video uploaded — subscribe to its comment feed and sync
                ProcessYoutubeWebhook::dispatch($videoId, 'new_video');
            } else {
                // New comment on a subscribed video
                ProcessYoutubeWebhook::dispatch($videoId, 'new_comment');
            }

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('YouTube webhook processing error', [
                'error' => $e->getMessage(),
                'body_preview' => substr($body, 0, 500),
            ]);

            // Return 200 even on errors — hub retries on 5xx, and we don't want spam
            return response('Error', 200);
        }
    }
}
