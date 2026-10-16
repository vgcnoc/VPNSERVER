<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->organizations()
            ->whereKey($organization->getKey())
            ->where('organizations.status', 'active')
            ->wherePivot('status', 'active')
            ->wherePivot('is_owner', true)
            ->exists();
    }
}
