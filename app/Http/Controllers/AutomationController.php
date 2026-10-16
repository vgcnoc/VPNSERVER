<?php

namespace App\Http\Controllers;

use App\Support\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class AutomationController extends Controller
{
    public function index(OrganizationContext $organizationContext): View
    {
        abort_unless($organizationContext->id(), 403);

        $organizationId = $organizationContext->id();

        return view('automation.index', [
            'organization' => $organizationContext->organization(),
            'routerConfigured' => (bool) config('routeros.enabled'),
            'paymentConfigured' => filled(config('midtrans.server_key')),
            'queuedJobs' => DB::table('provisioning_jobs')->where('organization_id', $organizationId)->where('status', 'queued')->count(),
            'failedJobs' => DB::table('provisioning_jobs')->where('organization_id', $organizationId)->where('status', 'failed')->count(),
            'recentJobs' => DB::table('provisioning_jobs')->where('organization_id', $organizationId)->latest('created_at')->limit(10)->get(),
        ]);
    }
}
