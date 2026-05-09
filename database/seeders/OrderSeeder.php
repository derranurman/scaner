<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Variant;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $samples = [
            [
                'resi' => 'JP0000000001',
                'buyer' => 'Budi Santoso',
                'items' => [
                    ['sku' => 'STIR-SKL-RED', 'qty' => 1],
                ],
            ],
            [
                'resi' => 'JP0000000002',
                'buyer' => 'Siti Aisyah',
                'items' => [
                    ['sku' => 'STIR-SKL-BLK', 'qty' => 1],
                    ['sku' => 'BSK-MTR-STD', 'qty' => 1],
                ],
            ],
            [
                'resi' => 'JP0000000003',
                'buyer' => 'Andi Wijaya',
                'items' => [
                    ['sku' => 'STIR-SKL-BLU', 'qty' => 2],
                    ['sku' => 'BSK-MTR-STD', 'qty' => 1],
                    ['sku' => 'SPN-BE-BLK', 'qty' => 1],
                ],
            ],
        ];

        foreach ($samples as $sample) {
            $order = Order::updateOrCreate(
                ['resi_number' => $sample['resi']],
                [
                    'tiktok_order_id' => 'TT'.substr($sample['resi'], -6),
                    'courier' => 'JNT',
                    'buyer_name' => $sample['buyer'],
                    'status' => Order::STATUS_PENDING,
                    'order_date' => now(),
                ],
            );

            $order->items()->delete();

            foreach ($sample['items'] as $it) {
                $variant = Variant::where('sku', $it['sku'])->first();

                OrderItem::create([
                    'order_id' => $order->id,
                    'variant_id' => $variant?->id,
                    'product_name' => $variant?->product?->name ?? $it['sku'],
                    'variant_name' => $variant?->name,
                    'sku' => $it['sku'],
                    'quantity' => $it['qty'],
                ]);
            }
        }
    }
}
