<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Contracts\View\View;

class OrganizationController extends Controller
{
    public function index(OrganizationContext $organizationContext): View
    {
        $user = request()->user();
        $isPlatformAdmin = $user->hasRole('super-admin');

        if ($isPlatformAdmin) {
            $organizations = Organization::query()
                ->withCount(['members' => fn ($q) => $q->where('organization_user.status', 'active')])
                ->orderBy('name')
                ->paginate(25);
        } else {
            $organizations = $user->organizations()
                ->withCount(['members' => fn ($q) => $q->where('organization_user.status', 'active')])
                ->orderByPivot('status')
                ->orderBy('name')
                ->paginate(25);
        }

        return view('organizations.index', [
            'organization' => $organizationContext->organization(),
            'organizations' => $organizations,
            'isPlatformAdmin' => $isPlatformAdmin,
        ]);
    }
}
