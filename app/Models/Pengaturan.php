<?php

namespace App\Models;

use App\Services\PengaturanService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Satu baris per kunci di A4 "Kunci pengaturan". `nilai` disimpan sebagai JSON sehingga bisa berisi
 * string, angka, boolean, array, atau null sesuai kuncinya.
 */
#[Fillable(['kunci', 'nilai', 'grup'])]
class Pengaturan extends Model
{
    protected $table = 'pengaturan';

    /**
     * `PengaturanService` menyimpan semua kunci di cache; perubahan lewat jalur mana pun (service, seeder,
     * Tinker) membuang cache itu.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(PengaturanService::KUNCI_CACHE));
        static::deleted(fn () => Cache::forget(PengaturanService::KUNCI_CACHE));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nilai' => 'json',
        ];
    }
}
