<?php

// app/Models/KnowledgeSource.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeSource extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'uploaded_by_user_id',
        'name',
        'description',
        'type',
        'file_path',
        'original_filename',
        'file_size',
        'total_chunks',
        'raw_text',
        'metadata',
        'is_indexed',
        'indexed_at',
        'embedding_model_version'
    ];

    protected $casts = [
        'indexed_at' => 'datetime',
        'metadata' => 'array',
        'is_indexed' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function knowledgeChunks()
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function socialPosts()
    {
        return $this->belongsToMany(SocialPost::class, 'knowledge_source_social_post')
            ->withPivot(['usage_type', 'is_active'])
            ->withTimestamps();
    }

    public function chunks()
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}