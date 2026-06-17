<?php

// app/Models/SocialComment.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialComment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'platform',
        'social_post_id',
        'social_account_id',
        'parent_id',
        'root_id',
        'platform_comment_id',
        'platform_root_id',
        'platform_author_id',
        'author_name',
        'author_avatar_url',
        'author_profile_url',
        'content',
        'direction',
        'sentiment',
        'sentiment_score',
        'intent',
        'lead_score',
        'is_lead',
        'status',
        'is_flagged',
        'flag_reason',
        'commented_at',
        'intent_confidence',
        'ai_analysis_failed',
        'ai_error_message',
        'ai_analysis_completed_at',
        'ai_response_text',
        'sender_type',
        'is_own_comment',
        'reply_count',
        'raw_payload'
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'is_own_comment' => 'boolean',
        'commented_at' => 'datetime',
        'is_lead' => 'boolean',
        'is_flagged' => 'boolean',
    ];

    protected $searchable = ['content', 'author_name'];

    public function socialPost()
    {
        return $this->belongsTo(SocialPost::class);
    }

    public function socialAccount()
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function aiConversation()
    {
        return $this->hasOne(AiConversation::class);
    }

    public function lead()
    {
        return $this->hasOne(Lead::class);
    }

    public function parent()
    {
        return $this->belongsTo(SocialComment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(SocialComment::class, 'parent_id')->orderBy('commented_at');
    }

    public function root()
    {
        return $this->belongsTo(SocialComment::class, 'root_id');
    }

    public function threadReplies()
    {
        return $this->hasMany(SocialComment::class, 'root_id')
            ->whereColumn('id', '!=', 'root_id')
            ->orderBy('commented_at');
    }
}
