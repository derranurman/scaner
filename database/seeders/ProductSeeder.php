<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'name' => 'Stir Skeleton',
                'sku' => 'STIR-SKL',
                'description' => 'Stir motor model skeleton.',
                'variants' => [
                    ['name' => 'Merah', 'sku' => 'STIR-SKL-RED', 'stock' => 20, 'min_stock' => 3],
                    ['name' => 'Hitam', 'sku' => 'STIR-SKL-BLK', 'stock' => 25, 'min_stock' => 3],
                    ['name' => 'Biru',  'sku' => 'STIR-SKL-BLU', 'stock' => 15, 'min_stock' => 3],
                ],
            ],
            [
                'name' => 'Stir Racing Sparco R13',
                'sku' => 'STIR-SPR',
                'description' => 'Stir racing import Sparco R13" Universal.',
                'variants' => [
                    ['name' => 'Hitam', 'sku' => 'STIR-SPR-BLK', 'stock' => 20, 'min_stock' => 3],
                    ['name' => 'Merah', 'sku' => 'STIR-SPR-RED', 'stock' => 15, 'min_stock' => 3],
                ],
            ],
            [
                'name' => 'Boskit Motor',
                'sku' => 'BSK-MTR',
                'description' => 'Boskit motor universal.',
                'variants' => [
                    ['name' => 'Standar', 'sku' => 'BSK-MTR-STD', 'stock' => 40, 'min_stock' => 5],
                    ['name' => 'Ferio',   'sku' => 'BSK-MTR-FER', 'stock' => 25, 'min_stock' => 5],
                ],
            ],
            [
                'name' => 'Spion Bar End',
                'sku' => 'SPN-BE',
                'description' => 'Spion bar end untuk motor.',
                'variants' => [
                    ['name' => 'Hitam', 'sku' => 'SPN-BE-BLK', 'stock' => 30, 'min_stock' => 4],
                    ['name' => 'Silver', 'sku' => 'SPN-BE-SLV', 'stock' => 18, 'min_stock' => 4],
                ],
            ],
        ];

        foreach ($products as $data) {
            $variants = $data['variants'];
            unset($data['variants']);

            $product = Product::updateOrCreate(
                ['sku' => $data['sku']],
                $data + ['is_active' => true],
            );

            foreach ($variants as $v) {
                Variant::updateOrCreate(
                    ['sku' => $v['sku']],
                    $v + ['product_id' => $product->id],
                );
            }
        }
    }
}
