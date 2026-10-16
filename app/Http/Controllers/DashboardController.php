<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(OrganizationContext $organizationContext): View
    {
        $user = request()->user();
        $organization = $organizationContext->organization();
        $isPlatformAdmin = $user->hasRole('super-admin');

        $memberCount = $organization ? $organization->members()->wherePivot('status', 'active')->count() : 0;
        $auditQuery = AuditLog::query()->with('actor')->latest('occurred_at');

        if (! $isPlatformAdmin) {
            if ($organization && $user->hasPermission('audit.view')) {
                $auditQuery->where('organization_id', $organization->getKey());
            } else {
                $auditQuery->whereRaw('1 = 0');
            }
        }

        return view('dashboard', [
            'organization' => $organization,
            'roles' => $user->roles()->orderBy('name')->get(),
            'metrics' => [
                ['label' => $isPlatformAdmin ? 'Organizations' : 'Organization members', 'value' => $isPlatformAdmin ? Organization::query()->where('status', 'active')->count() : $memberCount, 'detail' => $isPlatformAdmin ? 'Active organizations' : 'Active members in this tenant'],
                ['label' => 'Platform users', 'value' => $isPlatformAdmin ? User::query()->count() : $memberCount, 'detail' => $isPlatformAdmin ? 'All registered accounts' : 'Visible inside your tenant'],
                ['label' => 'Roles', 'value' => $isPlatformAdmin ? Role::query()->count() : $user->roles()->count(), 'detail' => $isPlatformAdmin ? 'Defined access roles' : 'Assigned to your account'],
                ['label' => 'Permissions', 'value' => $isPlatformAdmin ? Permission::query()->count() : $user->roles()->withCount('permissions')->get()->sum('permissions_count'), 'detail' => $isPlatformAdmin ? 'Defined permission rules' : 'Granted through your roles'],
            ],
            'events' => $auditQuery->limit(8)->get(),
        ]);
    }
}
