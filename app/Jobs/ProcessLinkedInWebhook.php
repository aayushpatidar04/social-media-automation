<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\LinkedInService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessLinkedInWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(private array $event)
    {
    }

    public function handle()
    {
        $eventType = $this->event['eventType'] ?? null;
        $lifecycleState = $this->event['lifecycleState'] ?? null;
        $entity = $this->event['entity'] ?? null;

        if (!$eventType || !$lifecycleState) {
            Log::warning('LinkedIn webhook: missing eventType or lifecycleState', $this->event);
            return;
        }

        if ($eventType === 'COMMENT') {
            if ($lifecycleState === 'CREATED') {
                $this->handleCommentCreated($entity);
            } elseif ($lifecycleState === 'DELETED') {
                $this->handleCommentDeleted($entity);
            }
        } elseif ($eventType === 'SOCIAL_ACTION') {
            if ($lifecycleState === 'CREATED') {
                $this->handleSocialActionCreated($entity);
            }
        }
    }

    private function handleCommentCreated(string $entity): void
    {
        // Find the account by URN (urn:li:organization:XXX)
        $orgUrn = preg_replace('/:comment:.+$/', '', $entity);

        $account = SocialAccount::where('platform', 'linkedin')
            ->where('platform_account_id', $orgUrn)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            Log::warning('LinkedIn webhook: account not found', ['entity' => $entity]);
            return;
        }

        app(LinkedInService::class)->syncSingleCommentFromWebhook($account, $entity);
    }

    private function handleCommentDeleted(string $entity): void
    {

        // Extract comment URN from entity
        $commentUrn = $this->event['comment']['id'] ?? $entity;

        $account = SocialAccount::where('platform', 'linkedin')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return;
        }

        $comment = \App\Models\SocialComment::where('platform', 'linkedin')
            ->where('platform_comment_id', $commentUrn)
            ->first();

        if (!$comment) {
            Log::warning('LinkedIn: comment not found for delete', ['comment_urn' => $commentUrn]);
            return;
        }

        // Cascade delete children
        if ($comment->replies()->exists()) {
            \App\Models\SocialComment::where('platform', 'linkedin')
                ->where('root_id', $comment->root_id ?: $comment->id)
                ->where('id', '!=', $comment->id)
                ->update(['deleted_at' => now()]);
        }

        $comment->update(['deleted_at' => now()]);
    }

    private function handleSocialActionCreated(string $entity): void
    {
        
    }
}
