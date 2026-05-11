<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PACKED = 'packed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tiktok_order_id',
        'resi_number',
        'courier',
        'buyer_name',
        'buyer_phone',
        'host_live',
        'sender_name',
        'platform_deduction_id',
        'shipping_address',
        'status',
        'order_date',
        'packed_at',
        'packed_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'packed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function packedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by_user_id');
    }

    public function platformDeduction(): BelongsTo
    {
        return $this->belongsTo(PlatformDeduction::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPacked(): bool
    {
        return $this->status === self::STATUS_PACKED;
    }

    public function totalQty(): int
    {
        return (int) $this->items->sum('quantity');
    }
}
