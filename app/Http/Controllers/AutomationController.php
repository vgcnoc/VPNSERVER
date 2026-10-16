<?php

namespace App\Http\Controllers;

use App\Support\OrganizationContext;
use Illuminate\Contracts\View\View;

class AutomationController extends Controller
{
    public function index(OrganizationContext $organizationContext): View
    {
        return view('automation.index', [
            'organization' => $organizationContext->organization(),
        ]);
    }
}
