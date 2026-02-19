<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Link;

class LinkSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $links = [
            [
                'title' => 'Organization Portal',
                'url' => 'https://nso-portal.example.com',
                'description' => 'Official ORG Organization Portal',
                'category' => 'Organization',
                'display_order' => 1,
                'is_active' => true,
                'created_by' => 1,
            ],
            [
                'title' => 'Affiliate Dashboard',
                'url' => 'https://affiliate.example.com/dashboard',
                'description' => 'Dashboard for all affiliate organizations',
                'category' => 'Affiliate',
                'display_order' => 2,
                'is_active' => true,
                'created_by' => 1,
            ],
            [
                'title' => 'Membership Guidelines',
                'url' => 'https://nso-portal.example.com/membership-guidelines',
                'description' => 'Guidelines and policies for members',
                'category' => 'Members',
                'display_order' => 3,
                'is_active' => true,
                'created_by' => 1,
            ],
            [
                'title' => 'Research Committee',
                'url' => 'https://nso-portal.example.com/research',
                'description' => 'Access ORG research committee materials',
                'category' => 'Research',
                'display_order' => 4,
                'is_active' => true,
                'created_by' => 1,
            ],
            [
                'title' => 'Support Center',
                'url' => 'https://support.example.com',
                'description' => 'Help and support center for all members',
                'category' => 'Support',
                'display_order' => 5,
                'is_active' => true,
                'created_by' => 1,
            ],
        ];

        foreach ($links as $link) {
            Link::create($link);
        }
    }
}
