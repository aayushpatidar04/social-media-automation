<?php

// app/Models/Lead.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'social_comment_id',
        'platform_author_id',
        'author_name',
        'author_profile_url',
        'initial_message',
        'lead_type',
        'lead_score',
        'lead_status',
        'contact_email',
        'contact_phone',
        'company_name',
        'notes',
        'assigned_to_user_id',
        'assigned_at',
        'last_contacted_at'
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'last_contacted_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function socialComment()
    {
        return $this->belongsTo(SocialComment::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}