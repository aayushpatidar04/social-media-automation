<?php

// app/Models/SocialAccount.php
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Encryption\Encrypter;
 
class SocialAccount extends Model
{
    use SoftDeletes;
 
    protected $fillable = [
        'organization_id', 'user_id', 'platform', 'platform_account_id',
        'platform_account_name', 'platform_account_handle', 'profile_picture_url',
        'access_token', 'refresh_token', 'token_expires_at', 'platform_data',
        'metadata', 'status', 'error_message', 'last_synced_at', 'is_active', 'auto_reply_started_at'
    ];
 
    protected $casts = [
        'metadata' => 'array',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'platform_data' => 'array',
        'is_active' => 'boolean',
    ];
 
    protected $hidden = ['access_token', 'refresh_token'];
 
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
 
    public function user()
    {
        return $this->belongsTo(User::class);
    }
 
    public function socialPosts()
    {
        return $this->hasMany(SocialPost::class);
    }
 
    public function socialComments()
    {
        return $this->hasMany(SocialComment::class);
    }
 
    public function aiConversations()
    {
        return $this->hasMany(AiConversation::class);
    }
}
