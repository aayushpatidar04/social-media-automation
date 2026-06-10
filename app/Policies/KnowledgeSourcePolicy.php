<?php

// app/Policies/KnowledgeSourcePolicy.php
 
namespace App\Policies;
 
use App\Models\User;
use App\Models\KnowledgeSource;
 
class KnowledgeSourcePolicy
{
    public function viewAny(User $user)
    {
        return true;
    }
 
    public function view(User $user, KnowledgeSource $source)
    {
        return $user->organization_id === $source->organization_id;
    }
 
    public function create(User $user)
    {
        return true;
    }
 
    public function delete(User $user, KnowledgeSource $source)
    {
        return $user->organization_id === $source->organization_id 
            && in_array($user->role, ['admin', 'manager']);
    }
}