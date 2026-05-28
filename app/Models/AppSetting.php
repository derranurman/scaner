<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Singleton settings (1 row only). Hold app_name + logo_path (di disk
 * public). Akses lewat AppSetting::current() — di-cache forever, dan
 * cache di-bust oleh observer di AppServiceProvider tiap kali ada
 * update.
 */
class AppSetting extends Model
{
    protected $fillable = [
        'app_name',
        'logo_path',
    ];

    public const CACHE_KEY = 'app_settings:current';

    /**
     * Ambil instance setting (cached forever; di-bust saat update).
     * Kalau row belum ada (misal migrasi belum jalan), return instance
     * default in-memory supaya view tidak crash.
     */
    public static function current(): self
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $row = static::query()->orderBy('id')->first();
            if ($row !== null) {
                return $row;
            }

            // Fallback in-memory (misal migrate belum jalan / table masih
            // kosong). Tidak di-persist ke DB di sini — kontrol penuh di
            // controller yang firstOrCreate sebelum update.
            return new self([
                'app_name' => config('app.name', 'Scaner Toko'),
                'logo_path' => null,
            ]);
        });
    }

    /**
     * Bust cache — dipanggil dari observer setelah save/delete.
     */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    /**
     * Inisial pertama nama untuk fallback logo (mis. "Scaner Toko" -> "S").
     */
    public function initial(): string
    {
        $name = trim((string) $this->app_name);
        if ($name === '') {
            return '?';
        }

        return mb_strtoupper(mb_substr($name, 0, 1));
    }
}
