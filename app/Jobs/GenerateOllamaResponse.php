<?php

namespace App\Jobs;

use App\Models\SocialComment;
use App\Models\AiConversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateOllamaResponse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;

    private SocialComment $comment;

    public function __construct(SocialComment $comment)
    {
        $this->comment = $comment;
    }

    public function handle()
    {
        try {

            $account = $this->comment->socialAccount;

            // Only reply to comments since auto-reply was turned on
            // This uses auto_reply_started_at (the actual date), NOT a day window
            // so it correctly handles comments from any time period as long as
            // they came in after auto-reply was enabled
            if (!$account || !$account->auto_reply_started_at) {
                return;
            }

            if (!$this->comment->commented_at || $this->comment->commented_at->lt($account->auto_reply_started_at)) {
                return;
            }

            if ($this->comment->is_own_comment) {
                return;
            }

            $service = new OllamaService();

            $conversationHistory = $this->buildConversationHistory($this->comment);

            $context = [
                'knowledge' => $this->getRelevantKnowledge($this->comment),
                'conversation_history' => $conversationHistory,
            ];

            $response = $service->generateResponse(
                $this->comment,
                $context
            );

            if (empty($response)) {
                Log::warning('Empty response from AI');
                return;
            }

            $aiConversation = AiConversation::create([
                'original_comment' => $this->comment->content,
                'social_comment_id' => $this->comment->id,
                'social_account_id' => $this->comment->social_account_id,
                'ai_response' => $response,
                'response_status' => 'pending',
                'confidence_score' => $this->comment->intent_confidence ?? 80,
                'model_used' => 'ollama_gemma2',
            ]);

            $this->comment->update([
                'ai_response_text' => $response,
            ]);

            PublishAutoReply::dispatch($this->comment);

        } catch (\Exception $e) {
            Log::error('Error generating response: ' . $e->getMessage());
            throw $e;
        }
    }

    private function getRelevantKnowledge(SocialComment $comment): string
    {
        $post = $comment->socialPost;

        if (!$post) {
            return '';
        }

        $postSpecificKnowledge = KnowledgeChunk::whereHas('source.socialPosts', function ($q) use ($post) {
            $q->where('social_posts.id', $post->id)
                ->where('knowledge_source_social_post.is_active', true);
        })
            ->limit(5)
            ->pluck('content')
            ->join("\n\n");

        $globalKnowledge = KnowledgeChunk::whereHas('source', function ($q) use ($comment) {
            $q->where('organization_id', $comment->socialAccount->organization_id)
                ->where('scope', 'global')
                ->where('is_active', true);
        })
            ->limit(3)
            ->pluck('content')
            ->join("\n\n");

        return trim($postSpecificKnowledge . "\n\n" . $globalKnowledge);
    }

    private function buildConversationHistory(SocialComment $comment): string
    {
        $rootId = $comment->root_id ?: $comment->id;

        $messages = SocialComment::where('root_id', $rootId)
            ->where('id', '!=', $comment->id)
            ->orderBy('commented_at')
            ->limit(20)
            ->get();

        if ($messages->isEmpty()) {
            return 'No previous conversation history.';
        }

        $history = [];

        foreach ($messages as $message) {
            $role = $message->direction === 'outbound'
                ? 'Assistant'
                : 'Customer';

            $history[] = "{$role}: {$message->content}";
        }

        return implode("\n", $history);
    }
}
