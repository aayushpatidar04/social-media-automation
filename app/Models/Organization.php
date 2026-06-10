<?php

// app/Models/Organization.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'logo_url',
        'website',
        'plan',
        'plan_expires_at',
        'settings',
        'is_active'
    ];

    protected $casts = [
        'plan_expires_at' => 'datetime',
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function socialComments()
    {
        return $this->hasManyThrough(SocialComment::class, SocialAccount::class);
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function knowledgeSources()
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}