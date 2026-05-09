<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_PACKING = 'packing';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function packingLogs(): HasMany
    {
        return $this->hasMany(PackingLog::class);
    }

    public function packedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'packed_by_user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isPacking(): bool
    {
        return $this->role === self::ROLE_PACKING;
    }
}
