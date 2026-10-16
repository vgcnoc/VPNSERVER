<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'View platform dashboard', 'slug' => 'platform.dashboard.view', 'group' => 'platform'],
            ['name' => 'Manage organizations', 'slug' => 'organizations.manage', 'group' => 'organizations'],
            ['name' => 'Manage users', 'slug' => 'users.manage', 'group' => 'identity'],
            ['name' => 'View audit logs', 'slug' => 'audit.view', 'group' => 'security'],
            ['name' => 'Manage infrastructure', 'slug' => 'infrastructure.manage', 'group' => 'infrastructure'],
            ['name' => 'Manage billing', 'slug' => 'billing.manage', 'group' => 'billing'],
            ['name' => 'Manage customers', 'slug' => 'customers.manage', 'group' => 'customers'],
            ['name' => 'View reports', 'slug' => 'reports.view', 'group' => 'reports'],
        ];

        $permissionIds = collect($permissions)->mapWithKeys(function (array $permission) {
            $model = Permission::query()->updateOrCreate(['slug' => $permission['slug']], $permission);
            return [$permission['slug'] => $model->getKey()];
        });

        $roles = [
            'super-admin' => ['Super Admin', 'Full platform access', $permissionIds->values()->all()],
            'infrastructure-admin' => ['Infrastructure Admin', 'VPN and server operations', ['infrastructure.manage', 'audit.view', 'platform.dashboard.view']],
            'tenant-owner' => ['Tenant Owner', 'Own organization management', ['organizations.manage', 'users.manage', 'customers.manage', 'reports.view']],
            'tenant-operator' => ['Tenant Operator', 'Tenant operations', ['customers.manage', 'reports.view']],
            'finance' => ['Finance', 'Billing and financial reporting', ['billing.manage', 'reports.view']],
            'support' => ['Support', 'Customer support operations', ['customers.manage']],
            'reseller' => ['Reseller', 'Reseller customer management', ['customers.manage', 'reports.view']],
            'customer' => ['Customer', 'Customer portal access', []],
            'auditor' => ['Auditor Read-only', 'Read-only audit access', ['audit.view', 'reports.view']],
        ];

        foreach ($roles as $slug => [$name, $description, $rolePermissions]) {
            $role = Role::query()->updateOrCreate(['slug' => $slug], compact('name', 'description'));
            $ids = collect($rolePermissions)->map(fn (string $permission) => $permissionIds[$permission] ?? $permission)->all();
            $role->permissions()->sync($ids);
        }
    }
}
