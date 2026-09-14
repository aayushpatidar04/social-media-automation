<?php

namespace App\Jobs;

use App\Models\SocialComment;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Log;

trait CascadeDeleteComments
{
    /**
     * Soft-delete a comment and cascade-delete all its children.
     *
     * @param string $platform Platform slug (e.g. 'facebook', 'instagram', 'youtube', 'twitter', 'linkedin')
     * @param string $platformCommentId The platform-specific comment ID
     * @return void
     */
    protected function cascadeDeleteComment(string $platform, string $platformCommentId): void
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

        // Cascade-delete children using platform_parent_id
        SocialComment::where('platform', $platform)
            ->where('platform_parent_id', $platformCommentId)
            ->where('id', '!=', $comment->id)
            ->update(['deleted_at' => now()]);

        // Also cascade by root_id in case they weren't linked by parent_id
        SocialComment::where('platform', $platform)
            ->where('root_id', $comment->root_id ?: $comment->id)
            ->where('id', '!=', $comment->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        // Soft-delete the comment itself
        $comment->update(['deleted_at' => now()]);

        Log::info('Cascade deleted comment', [
            'platform' => $platform,
            'comment_id' => $comment->id,
            'platform_comment_id' => $platformCommentId,
        ]);
    }

    /**
     * Soft-delete all comments for a post when the post is deleted on the platform.
     *
     * @param string $platform
     * @param string $platformPostId
     * @return void
     */
    protected function cascadeDeletePost(string $platform, string $platformPostId): void
    {
        SocialPost::where('platform', $platform)
            ->where('platform_post_id', $platformPostId)
            ->update(['deleted_at' => now()]);

        SocialComment::where('platform', $platform)
            ->whereHas('socialPost', function ($q) use ($platformPostId) {
                $q->where('platform_post_id', $platformPostId);
            })
            ->update(['deleted_at' => now()]);

        Log::info('Cascade deleted post and all its comments', [
            'platform' => $platform,
            'platform_post_id' => $platformPostId,
        ]);
    }
}
