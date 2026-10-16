<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DevelopmentAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Development administrator seeding is restricted to local and testing environments.');
        }

        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'vpnrent-platform'],
            ['name' => 'VPNRENT Platform', 'status' => 'active', 'timezone' => 'Asia/Jakarta']
        );

        $user = User::query()->firstOrCreate(
            ['email' => 'admin@vpnrent.test'],
            [
                'name' => 'VPNRENT Administrator',
                'password' => Hash::make(env('VPNRENT_DEV_ADMIN_PASSWORD', 'ChangeMe123!')),
                'email_verified_at' => now(),
            ]
        );

        $user->organizations()->syncWithoutDetaching([$organization->getKey() => ['is_owner' => true, 'status' => 'active']]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('slug', 'super-admin')->value('id')]);

        app(AuditService::class)->record('development.seeded', $user, $organization, ['source' => 'DevelopmentAdminSeeder'], $organization);
    }
}
