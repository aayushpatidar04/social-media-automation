<?php

namespace App\Jobs;

use App\Models\SocialComment;
use App\Services\FacebookService;
use App\Services\InstagramService;
use App\Services\TwitterService;
use App\Services\YoutubeService;
use App\Services\LinkedInService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 120, 300];

    private SocialComment $comment;

    public function __construct(SocialComment $comment)
    {
        $this->comment = $comment;
    }

    public function handle(): void
    {
        try {
            $aiConversation = $this->comment->aiConversation;
            if (!$aiConversation || !$aiConversation->ai_response) {
                return;
            }

            $service = null;

            if ($this->comment->platform === 'facebook') {
                $service = new FacebookService();
            } elseif ($this->comment->platform === 'instagram') {
                $service = new InstagramService();
            } elseif ($this->comment->platform === 'twitter') {
                $service = new TwitterService();
            } elseif ($this->comment->platform === 'youtube') {
                $service = new YoutubeService();
            } elseif ($this->comment->platform === 'linkedin') {
                $service = new LinkedInService();
            }

            if (!$service) {
                Log::warning('Auto reply not supported for platform: ' . $this->comment->platform);
                return;
            }

            $response = $service->publishReply(
                $this->comment,
                $aiConversation->ai_response,
                $this->comment->socialAccount
            );

            if ($this->comment->platform === 'facebook') {
                $this->storeFacebookOutboundReply(
                    $this->comment,
                    $aiConversation->ai_response,
                    is_array($response) ? $response : []
                );
            }

            if ($this->comment->platform === 'instagram') {
                $this->storeInstagramOutboundReply(
                    $this->comment,
                    $aiConversation->ai_response,
                    is_array($response) ? $response : []
                );
            }

            if ($this->comment->platform === 'youtube') {
                $this->storeYouTubeOutboundReply(
                    $this->comment,
                    $aiConversation->ai_response,
                    is_array($response) ? $response : []
                );
            }

            $aiConversation->update([
                'response_status' => 'auto_sent',
                'sent_at' => now(),
                'send_status' => 'success',
            ]);

            $this->comment->update([
                'status' => 'replied',
                'ai_response_text' => $aiConversation->ai_response,
                'replied_at' => now(),
            ]);

            Log::info("Auto reply sent for comment {$this->comment->id}");

        } catch (\Exception $e) {
            Log::error("Publish Reply Error: " . $e->getMessage());

            // Update error status
            if ($this->comment->aiConversation()) {
                $this->comment->aiConversation()->update([
                    'send_status' => 'failed',
                    'send_error_message' => $e->getMessage(),
                ]);
            }

            $this->fail($e);
        }
    }

    private function storeFacebookOutboundReply(SocialComment $parentComment, string $message, array $response): void
    {
        $replyId = $response['id'] ?? null;

        if (!$replyId) {
            Log::warning('Facebook reply id missing, outbound comment not stored', [
                'response' => $response,
                'parent_comment_id' => $parentComment->id,
            ]);

            return;
        }

        $rootId = $parentComment->root_id ?: $parentComment->id;
        $platformRootId = $parentComment->platform_root_id ?: $parentComment->platform_comment_id;

        SocialComment::updateOrCreate(
            [
                'platform' => 'facebook',
                'platform_comment_id' => $replyId,
            ],
            [
                'social_account_id' => $parentComment->social_account_id,
                'social_post_id' => $parentComment->social_post_id,

                'parent_id' => $parentComment->id,
                'root_id' => $rootId,

                'platform_parent_id' => $parentComment->platform_comment_id,
                'platform_root_id' => $platformRootId,

                'author_name' => $parentComment->socialAccount->name ?? 'Page',
                'platform_author_id' => $parentComment->socialAccount->platform_account_id,

                'content' => $message,
                'direction' => 'outbound',
                'sender_type' => 'ai',
                'is_own_comment' => true,

                'raw_payload' => $response,
                'commented_at' => now(),
                'status' => 'sent',
            ]
        );

        $parentComment->increment('reply_count');

        SocialComment::where('id', $rootId)->increment('reply_count');
    }

    private function storeInstagramOutboundReply(SocialComment $parentComment, string $message, array $response): void
    {
        $replyId = $response['id'] ?? null;

        if (!$replyId) {
            Log::warning('Instagram reply id missing, outbound comment not stored', [
                'response' => $response,
                'parent_comment_id' => $parentComment->id,
            ]);

            return;
        }

        $rootId = $parentComment->root_id ?: $parentComment->id;
        $platformRootId = $parentComment->platform_root_id ?: $parentComment->platform_comment_id;

        SocialComment::updateOrCreate(
            [
                'platform' => 'instagram',
                'platform_comment_id' => $replyId,
            ],
            [
                'organization_id' => $parentComment->organization_id,
                'social_account_id' => $parentComment->social_account_id,
                'social_post_id' => $parentComment->social_post_id,

                'parent_id' => $parentComment->id,
                'root_id' => $rootId,

                'platform_parent_id' => $parentComment->platform_comment_id,
                'platform_root_id' => $platformRootId,

                'author_name' => $parentComment->socialAccount->name ?? 'Instagram Account',
                'platform_author_id' => data_get($parentComment->socialAccount->metadata, 'connected_instagram_account_id')
                    ?? $parentComment->socialAccount->platform_account_id,

                'content' => $message,
                'direction' => 'outbound',
                'sender_type' => 'ai',
                'is_own_comment' => true,

                'raw_payload' => $response,
                'commented_at' => now(),
                'status' => 'sent',
            ]
        );

        $parentComment->increment('reply_count');

        SocialComment::where('id', $rootId)->increment('reply_count');
    }

    private function storeYouTubeOutboundReply(SocialComment $parentComment, string $message, array $response): void
    {
        $replyId = $response['id'] ?? null;

        if (!$replyId) {
            Log::warning('YouTube reply id missing, outbound comment not stored', [
                'response' => $response,
                'parent_comment_id' => $parentComment->id,
            ]);

            return;
        }

        $rootId = $parentComment->root_id ?: $parentComment->id;
        $platformRootId = $parentComment->platform_root_id ?: $parentComment->platform_comment_id;

        SocialComment::updateOrCreate(
            [
                'platform' => 'youtube',
                'platform_comment_id' => $replyId,
            ],
            [
                'organization_id' => $parentComment->organization_id,
                'social_account_id' => $parentComment->social_account_id,
                'social_post_id' => $parentComment->social_post_id,

                'parent_id' => $parentComment->id,
                'root_id' => $rootId,

                'platform_parent_id' => $parentComment->platform_comment_id,
                'platform_root_id' => $platformRootId,

                'author_name' => $parentComment->socialAccount->name ?? 'YouTube Channel',
                'platform_author_id' => $parentComment->socialAccount->platform_account_id,

                'content' => $message,
                'direction' => 'outbound',
                'sender_type' => 'ai',
                'is_own_comment' => true,

                'raw_payload' => $response,
                'commented_at' => now(),
                'status' => 'sent',
            ]
        );

        $parentComment->increment('reply_count');

        SocialComment::where('id', $rootId)->increment('reply_count');
    }
}