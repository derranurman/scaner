<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_name',
        'adm_percent',
        'cashback_percent',
        'free_shipping_percent',
        'shipping_cargo_amount',
        'label_amount',
        'yield_percent',
        'packaging_amount',
        'operational_percent',
        'service_fee_amount',
        'logistics_amount',
        'tax_percent',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'adm_percent' => 'decimal:4',
            'cashback_percent' => 'decimal:4',
            'free_shipping_percent' => 'decimal:4',
            'shipping_cargo_amount' => 'decimal:2',
            'label_amount' => 'decimal:2',
            'yield_percent' => 'decimal:4',
            'packaging_amount' => 'decimal:2',
            'operational_percent' => 'decimal:4',
            'service_fee_amount' => 'decimal:2',
            'logistics_amount' => 'decimal:2',
            'tax_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Total semua potongan persen (untuk ringkasan di tabel).
     */
    public function totalPercent(): float
    {
        return (float) $this->adm_percent
            + (float) $this->cashback_percent
            + (float) $this->free_shipping_percent
            + (float) $this->yield_percent
            + (float) $this->operational_percent
            + (float) $this->tax_percent;
    }

    /**
     * Total semua potongan nominal (Rp).
     */
    public function totalAmount(): float
    {
        return (float) $this->shipping_cargo_amount
            + (float) $this->label_amount
            + (float) $this->packaging_amount
            + (float) $this->service_fee_amount
            + (float) $this->logistics_amount;
    }
}
