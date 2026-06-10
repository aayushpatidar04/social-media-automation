<?php
 
// app/Policies/SocialAccountPolicy.php
 
namespace App\Policies;
 
use App\Models\User;
use App\Models\SocialAccount;
 
class SocialAccountPolicy
{
    public function viewAny(User $user)
    {
        return true;
    }
 
    public function view(User $user, SocialAccount $account)
    {
        return $user->organization_id === $account->organization_id;
    }
 
    public function create(User $user)
    {
        return true;
    }
 
    public function update(User $user, SocialAccount $account)
    {
        return $user->organization_id === $account->organization_id 
            && in_array($user->role, ['admin', 'manager']);
    }
 
    public function delete(User $user, SocialAccount $account)
    {
        return $user->organization_id === $account->organization_id 
            && in_array($user->role, ['admin', 'manager']);
    }
}