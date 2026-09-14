<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Services\YoutubeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessYoutubeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        private string $atomXml,
        private string $videoId
    ) {
    }

    public function handle()
    {
        try {
            // Find the YouTube account that owns this video
            $account = SocialAccount::where('platform', 'youtube')
                ->where('is_active', true)
                ->whereJsonContains('metadata->last_video_ids', $this->videoId)
                ->orWhereHas('posts', function ($q) {
                    $q->where('platform_post_id', $this->videoId);
                })
                ->first();

            if (!$account) {
                // Try matching by video via the search API
                Log::warning('YouTube webhook: account not found for video, fetching video info', [
                    'video_id' => $this->videoId,
                ]);
            }

            if ($account) {
                // Fetch and process the new comment(s) for this video
                $service = new YoutubeService();
                $service->syncNewCommentsForVideo($account, $this->videoId);
            }

            // Check if this is a deletion notification
            // YouTube PubSubHubbub sends `deleted-entry` entries for deletions
            $namespaces = $this->extractNamespaces();
            $atomNs = $namespaces['atom'] ?? 'http://www.w3.org/2005/Atom';

            try {
                $xml = simplexml_load_string($this->atomXml);
                if ($xml) {
                    $entry = $xml->children($atomNs)->entry;
                    if ($entry) {
                        $entryId = (string) $entry->children($atomNs)->id;
                        // Format: "tag:youtube.com,2008:video:VIDEO_ID:comment:COMMENT_ID"
                        if (preg_match('/comment:([a-zA-Z0-9_-]+)/', $entryId, $matches)) {
                            $commentId = $matches[1];

                            // Check if this is a deletion (yt:notDeleted) vs new comment
                            $ytNs = $namespaces['yt'] ?? 'http://www.youtube.com/xml/schemas/2015';
                            $ytChildren = $entry->children($ytNs);
                            $isDeleted = isset($ytChildren->{'notDeleted'})
                                ? !(string) $ytChildren->{'notDeleted'} === 'true'
                                : false;

                            if ($isDeleted) {
                                Log::info('YouTube comment deleted via webhook', [
                                    'comment_id' => $commentId,
                                    'video_id' => $this->videoId,
                                ]);
                                $this->cascadeDeleteComment('youtube', $commentId);
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('YouTube webhook: could not parse deletion info', [
                    'error' => $e->getMessage(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('YouTube webhook processing failed', [
                'error' => $e->getMessage(),
                'video_id' => $this->videoId,
            ]);
            throw $e;
        }
    }

    private function extractNamespaces(): array
    {
        try {
            $xml = simplexml_load_string($this->atomXml);
            return $xml ? $xml->getNamespaces(true) : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Cascade-delete a YouTube comment.
     */
    private function cascadeDeleteComment(string $platform, string $platformCommentId): void
    {
        $comment = SocialComment::where('platform', $platform)
            ->where('platform_comment_id', $platformCommentId)
            ->first();

        if (!$comment) {
            Log::warning('Comment not found for cascade delete', [
                'platform' => $platform,
                'platform_comment_id' => $platformCommentId,
            ]);
            return;
        }

        if ($comment->replies()->exists()) {
            SocialComment::where('platform', $platform)
                ->where('root_id', $comment->root_id ?: $comment->id)
                ->where('id', '!=', $comment->id)
                ->update(['deleted_at' => now()]);
        }

        SocialComment::where('platform', $platform)
            ->where('platform_parent_id', $platformCommentId)
            ->where('id', '!=', $comment->id)
            ->update(['deleted_at' => now()]);

        $comment->update(['deleted_at' => now()]);

        Log::info('Cascade deleted comment', [
            'platform' => $platform,
            'comment_id' => $comment->id,
            'platform_comment_id' => $platformCommentId,
        ]);
    }
}
