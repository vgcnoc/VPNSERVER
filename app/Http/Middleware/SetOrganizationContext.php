<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        app(OrganizationContext::class)->set(null);

        if (! $user) {
            return $next($request);
        }

        $organizationId = $request->session()->get('active_organization_id');
        $organization = $organizationId
            ? $user->organizations()->whereKey($organizationId)->where('organizations.status', 'active')->wherePivot('status', 'active')->first()
            : $user->organizations()->where('organizations.status', 'active')->wherePivot('status', 'active')->orderByPivot('is_owner', 'desc')->first();

        if ($organization) {
            $request->session()->put('active_organization_id', $organization->getKey());
        } else {
            $request->session()->forget('active_organization_id');
        }

        app(OrganizationContext::class)->set($organization);

        return $next($request);
    }
}
