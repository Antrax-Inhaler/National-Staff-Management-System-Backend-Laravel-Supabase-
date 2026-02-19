<?php

namespace Database\Seeders\v1;

use App\Enums\OfficersEnum;
use App\Models\OfficerPosition;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OfficersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (OfficersEnum::cases() as $position) {
            OfficerPosition::updateOrCreate(
                ['name' => $position->value],   // search by unique name
                ['display_order' => $position->order()] // update or insert this
            );
        }
    }
}
