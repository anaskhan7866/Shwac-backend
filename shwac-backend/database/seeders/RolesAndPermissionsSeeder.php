<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Idempotent: safe to re-run after adding permissions to config/shwac.php. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('shwac.permissions') as $slug => [$group, $name]) {
            Permission::updateOrCreate(['slug' => $slug], ['group' => $group, 'name' => $name]);
        }
        $all = Permission::pluck('id', 'slug');

        foreach (config('shwac.roles') as $slug => [$name, $perms]) {
            $role = Role::updateOrCreate(['slug' => $slug], ['name' => $name, 'is_system' => true]);
            $ids = $perms === ['*'] ? $all->values() : $all->only($perms)->values();
            // only add — never strip permissions an admin granted from the UI
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }
}
