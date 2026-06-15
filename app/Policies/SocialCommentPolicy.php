<?php

// app/Policies/SocialCommentPolicy.php
 
namespace App\Policies;
 
use App\Models\User;
use App\Models\SocialComment;
 
class SocialCommentPolicy
{
    public function viewAny(User $user)
    {
        return true;
    }
 
    public function view(User $user, SocialComment $comment)
    {
        return $user->organization_id === $comment->socialAccount->organization_id;
    }
 
    public function update(User $user, SocialComment $comment)
    {
        return $user->organization_id === $comment->socialAccount->organization_id;
    }
 
    public function delete(User $user, SocialComment $comment)
    {
        return $user->organization_id === $comment->socialAccount->organization_id 
            && in_array($user->role, ['admin', 'manager']);
    }
}