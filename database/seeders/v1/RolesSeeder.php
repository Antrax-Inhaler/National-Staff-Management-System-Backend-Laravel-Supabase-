<?php

namespace Database\Seeders\v1;

use App\Enums\RoleEnum;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        foreach (RoleEnum::topRoles() as $role) {
            Role::updateOrCreate(
                ["name" => $role->value],
                [
                    "description" => $role->description(),
                    "parent_role_id" => null,
                    'order' => $role->order()
                ]
            );
        }

        foreach (RoleEnum::cases() as $role) {
            Role::updateOrCreate(
                ["name" => $role->value],
                [
                    "description" => $role->description(),
                    // "parent_role_id" => Role::firstWhere("name", $role->parent())?->id,
                    'order' => $role->order()
                ]
            );
        }
    }
}
