<?php

namespace Database\Seeders;

use App\Models\CatalogGift;
use Illuminate\Database\Seeder;

class CatalogGiftSeeder extends Seeder
{
    public function run(): void
    {
        $gifts = [
            [
                'name' => 'Maxi Microwave Oven',
                'category' => 'Kitchen',
                'price' => 45000,
                'image' => 'https://images.unsplash.com/photo-1585771724684-38269d6639fd?w=600&q=80',
            ],
            [
                'name' => 'Air Fryer 5.5L',
                'category' => 'Kitchen',
                'price' => 62000,
                'image' => 'https://images.unsplash.com/photo-1648170293833-6ce2147ea297?w=600&q=80',
            ],
            [
                'name' => 'Portable AC Unit',
                'category' => 'Home',
                'price' => 180000,
                'image' => 'https://images.unsplash.com/photo-1585771724684-38269d6639fd?w=600&q=80',
            ],
            [
                'name' => 'Gaming Chair & Desk Set',
                'category' => 'Furniture',
                'price' => 95000,
                'image' => 'https://images.unsplash.com/photo-1598032895397-b9472444bf93?w=600&q=80',
            ],
            [
                'name' => 'Blender & Juicer Combo',
                'category' => 'Kitchen',
                'price' => 28000,
                'image' => 'https://images.unsplash.com/photo-1570222094114-d054a817e56b?w=600&q=80',
            ],
            [
                'name' => 'Smart TV 43"',
                'category' => 'Electronics',
                'price' => 250000,
                'image' => 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=600&q=80',
            ],
            [
                'name' => 'Dinner Set (12-piece)',
                'category' => 'Kitchen',
                'price' => 35000,
                'image' => 'https://images.unsplash.com/photo-1603199506016-5a4f1f4e1f8d?w=600&q=80',
            ],
            [
                'name' => 'Bed Frame (King Size)',
                'category' => 'Furniture',
                'price' => 120000,
                'image' => 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?w=600&q=80',
            ],
            [
                'name' => 'Wall Art Set',
                'category' => 'Decor',
                'price' => 18000,
                'image' => 'https://images.unsplash.com/photo-1500462918059-b1a0cb512f1d?w=600&q=80',
            ],
            [
                'name' => 'Washing Machine 7kg',
                'category' => 'Home',
                'price' => 195000,
                'image' => 'https://images.unsplash.com/photo-1626806787461-102c1a7f1b34?w=600&q=80',
            ],
            [
                'name' => 'Coffee Maker',
                'category' => 'Kitchen',
                'price' => 22000,
                'image' => 'https://images.unsplash.com/photo-1517668808822-9ebb02f2a0e6?w=600&q=80',
            ],
            [
                'name' => 'Sound System / Speaker',
                'category' => 'Electronics',
                'price' => 75000,
                'image' => 'https://images.unsplash.com/photo-1608043152269-423dbba4e7e1?w=600&q=80',
            ],
        ];

        foreach ($gifts as $gift) {
            CatalogGift::updateOrCreate(
                ['name' => $gift['name']],
                array_merge($gift, [
                    'description' => "A quality {$gift['name']} — perfect gift for any occasion.",
                ])
            );
        }
    }
}