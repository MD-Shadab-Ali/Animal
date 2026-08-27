<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Goat;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SellerSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'seller@example.test'],
            [
                'name'      => 'Karim Uddin',
                'role'      => 'customer',
                'phone'     => '+977 9802-222222',
                'password'  => 'password',
                'is_active' => true,
            ]
        );

        $seller = Seller::updateOrCreate(
            ['user_id' => $user->id],
            [
                'farm_name'     => 'Karim Livestock',
                'slug'          => 'karim-livestock',
                'bio'           => 'A family farm in Chitwan raising Black Bengal and Jamunapari goats for three generations. Every animal is hand-reared on green fodder.',
                'contact_phone' => '+977 9802-222222',
                'contact_email' => 'seller@example.test',
                'address_line'  => 'Ward 6, Ratnanagar',
                'area'          => 'Ratnanagar',
                'city'          => 'Chitwan',
                'status'        => 'approved',
                'approved_at'   => now(),
            ]
        );

        // One live listing and one waiting for review, so both admin queues and
        // the seller dashboard have something to show.
        $listings = [
            [
                'name' => 'Karim Khari Khasi', 'breed' => 'Khari',
                'category' => 'Dashain Goats', 'age' => 15, 'weight' => 24, 'gender' => 'male',
                'price' => 31000, 'approval' => 'approved', 'status' => 'published',
                'short' => 'Raised entirely on green fodder, calm to handle.',
            ],
            [
                'name' => 'Karim Jamunapari Doe', 'breed' => 'Jamunapari',
                'category' => 'Dairy Goats', 'age' => 26, 'weight' => 36, 'gender' => 'female',
                'price' => 58000, 'approval' => 'pending', 'status' => 'published',
                'short' => 'Second lactation, steady yield.',
            ],
        ];

        foreach ($listings as $index => $row) {
            $category = Category::where('name', $row['category'])->first() ?? Category::first();

            Goat::updateOrCreate(
                ['sku' => 'KL-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT)],
                [
                    'seller_id'       => $seller->id,
                    'category_id'     => $category->id,
                    'name'            => $row['name'],
                    // Unique even when two listings share a name.
                    'slug'            => Str::slug($row['name'].' '.$row['weight'].'kg'),
                    'breed'           => $row['breed'],
                    'age_months'      => $row['age'],
                    'weight_kg'       => $row['weight'],
                    'gender'          => $row['gender'],
                    'health_status'   => 'Vet checked — healthy',
                    'is_vaccinated'   => true,
                    'price'           => $row['price'],
                    'stock'           => 1,
                    'track_stock'     => true,
                    'short_description' => $row['short'],
                    'description'     => '<p>'.$row['short'].'</p><p>Listed by Karim Livestock and verified by our team before going on sale.</p>',
                    'status'          => $row['status'],
                    'approval_status' => $row['approval'],
                    'submitted_at'    => now()->subDay(),
                    'approved_at'     => $row['approval'] === 'approved' ? now()->subDay() : null,
                ]
            );
        }
    }
}
