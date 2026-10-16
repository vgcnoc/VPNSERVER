<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request, Organization $organization, AuditService $audit): RedirectResponse
    {
        abort_unless($request->user()->belongsToOrganization($organization), 403);

        $request->session()->put('active_organization_id', $organization->getKey());
        $audit->record('organization.context_switched', $request->user(), $organization, [], $organization);

        return to_route('dashboard')->with('status', 'Active organization changed.');
    }
}
