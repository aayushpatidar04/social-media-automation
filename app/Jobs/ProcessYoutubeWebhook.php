<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\YoutubeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessYoutubeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        private string $videoId,
        private string $eventType = 'new_comment'
    ) {
    }

    public function handle(YoutubeService $youtube)
    {
        try {
            $account = SocialAccount::where('platform', 'youtube')
                ->where('status', 'connected')
                ->first();

            if (!$account) {
                Log::warning('YouTube webhook: no connected account found');
                return;
            }

            switch ($this->eventType) {
                case 'new_video':
                    // New video uploaded — subscribe to its comment feed
                    $this->handleNewVideo($youtube, $account);
                    break;

                case 'new_comment':
                    // New comment detected — sync comments for this video
                    $this->handleNewComment($youtube, $account);
                    break;

                default:
                    Log::warning('YouTube webhook: unknown event type', [
                        'event_type' => $this->eventType,
                    ]);
            }

        } catch (\Exception $e) {
            Log::error('YouTube webhook job failed', [
                'video_id' => $this->videoId,
                'event_type' => $this->eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleNewVideo(YoutubeService $youtube, SocialAccount $account): void
    {
        Log::info('YouTube webhook: new video detected, subscribing', [
            'video_id' => $this->videoId,
        ]);

        // Store the video as a post
        $storedPost = SocialPost::updateOrCreate(
            [
                'platform_post_id' => $this->videoId,
                'platform' => 'youtube',
            ],
            [
                'organization_id' => $account->organization_id,
                'social_account_id' => $account->id,
                'content' => '',
                'url' => "https://www.youtube.com/watch?v={$this->videoId}",
                'posted_at' => now(),
            ]
        );

        // Subscribe to this video's comment feed via PubSubHubbub
        $hubUrl = 'https://pubsubhubbub.appspot.com/subscribe';
        $webhookUrl = rtrim(config('app.url'), '/') . '/webhooks/youtube';
        $topicUrl = "https://www.youtube.com/xml/feeds/videos.xml?video_id={$this->videoId}";

        $response = Http::asForm()->post($hubUrl, [
            'hub.callback' => $webhookUrl,
            'hub.topic' => $topicUrl,
            'hub.verify' => 'sync',
            'hub.mode' => 'subscribe',
            'hub.lease_seconds' => 864000,
        ]);

        if ($response->successful() || $response->status() === 204) {
            Log::info('YouTube webhook: subscribed to new video', [
                'video_id' => $this->videoId,
            ]);
        } else {
            Log::warning('YouTube webhook: subscription failed for new video', [
                'video_id' => $this->videoId,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 200),
            ]);
        }

        // Now sync comments for this new video
        $this->handleNewComment($youtube, $account);
    }

    private function handleNewComment(YoutubeService $youtube, SocialAccount $account): void
    {
        Log::info('YouTube webhook: syncing new comments', [
            'video_id' => $this->videoId,
        ]);

        $count = $youtube->syncNewCommentsForVideo(
            $account,
            $this->videoId,
            null,
            1,
            false
        );

        Log::info('YouTube webhook: comment sync done', [
            'video_id' => $this->videoId,
            'new_comments' => $count,
        ]);
    }
}
