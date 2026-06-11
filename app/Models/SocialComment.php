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
        'platform_comment_id',
        'platform_author_id',
        'author_name',
        'author_avatar_url',
        'author_profile_url',
        'content',
        'sentiment',
        'sentiment_score',
        'intent',
        'lead_score',
        'is_lead',
        'status',
        'is_flagged',
        'flag_reason',
        'commented_at'
    ];

    protected $casts = [
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
}
