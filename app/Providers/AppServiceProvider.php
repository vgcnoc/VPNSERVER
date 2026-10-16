<?php

namespace App\Providers;

use App\Models\Organization;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use App\Services\AuditService;
use App\Support\OrganizationContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(OrganizationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);

        Gate::before(function (User $user) {
            return $user->hasRole('super-admin') ? true : null;
        });

        Event::listen(Login::class, function (Login $event): void {
            app(AuditService::class)->record('auth.login', $event->user, $event->user, [
                'guard' => $event->guard,
            ]);
        });
    }
}
