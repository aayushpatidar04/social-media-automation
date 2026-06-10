<?php

// app/Policies/LeadPolicy.php
 
namespace App\Policies;
 
use App\Models\User;
use App\Models\Lead;
 
class LeadPolicy
{
    public function viewAny(User $user)
    {
        return true;
    }
 
    public function view(User $user, Lead $lead)
    {
        return $user->organization_id === $lead->organization_id;
    }
 
    public function update(User $user, Lead $lead)
    {
        return $user->organization_id === $lead->organization_id;
    }
 
    public function delete(User $user, Lead $lead)
    {
        return $user->organization_id === $lead->organization_id 
            && in_array($user->role, ['admin', 'manager']);
    }
}