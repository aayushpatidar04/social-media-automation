<?php

// app/Models/SocialPost.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialPost extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'platform',
        'social_account_id',
        'platform_post_id',
        'content',
        'post_url',
        'posted_by',
        'likes_count',
        'shares_count',
        'comments_count',
        'media_urls',
        'posted_at',
        'fetched_at',
        'raw_payload'
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'posted_at' => 'datetime',
        'fetched_at' => 'datetime',
        'media_urls' => 'array',
    ];

    public function socialAccount()
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function socialComments()
    {
        return $this->hasMany(SocialComment::class);
    }

    public function knowledgeSources()
    {
        return $this->belongsToMany(KnowledgeSource::class, 'knowledge_source_social_post')
            ->withPivot(['usage_type', 'is_active'])
            ->withTimestamps();
    }
}