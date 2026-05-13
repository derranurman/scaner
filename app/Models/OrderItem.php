<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'variant_id',
        'product_name',
        'variant_name',
        'sku',
        'kelengkapan',
        'harga_modal',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'harga_modal' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function displayName(): string
    {
        return trim($this->product_name.' — '.($this->variant_name ?? ''), ' —');
    }
}
