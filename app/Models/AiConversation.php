<?php

// app/Models/AiConversation.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    protected $fillable = [
        'social_comment_id',
        'social_account_id',
        'original_comment',
        'ai_analysis',
        'ai_response',
        'confidence_score',
        'requires_human_review',
        'review_reason',
        'human_override_response',
        'reviewed_by_user_id',
        'reviewed_at',
        'response_status',
        'sent_at',
        'sent_platform_id',
        'send_status',
        'send_error_message'
    ];

    protected $casts = [
        'ai_analysis' => 'array',
        'reviewed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function socialComment()
    {
        return $this->belongsTo(SocialComment::class);
    }

    public function socialAccount()
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
