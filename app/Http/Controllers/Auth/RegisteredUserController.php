<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Role;
use App\Services\AuditService;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $organization = Organization::query()->create([
                'name' => $user->name."'s Organization",
                'slug' => Str::slug($user->name).'-'.Str::lower(Str::random(6)),
                'status' => 'active',
                'timezone' => config('app.timezone'),
            ]);

            $user->organizations()->attach($organization->getKey(), ['is_owner' => true, 'status' => 'active']);
            $customerRoleId = Role::query()->where('slug', 'customer')->value('id');

            if ($customerRoleId) {
                $user->roles()->attach($customerRoleId);
            }

            app(AuditService::class)->record('organization.created', $user, $organization, ['source' => 'self_registration'], $organization);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);
        $request->session()->put('active_organization_id', $user->organizations()->value('organizations.id'));

        return redirect(route('dashboard', absolute: false));
    }
}
