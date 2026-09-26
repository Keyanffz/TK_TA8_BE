<?php

namespace App\Models;

use App\Enums\Hubungan;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot murid–wali. Tabel hanya punya `created_at`, tanpa `updated_at`.
 */
class MuridWali extends Pivot
{
    protected $table = 'murid_wali';

    /**
     * Pivot memakai nama kolom timestamp milik model induk (`updated_at` di Murid/WaliMurid),
     * jadi konstanta UPDATED_AT di sini tidak berpengaruh.
     */
    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hubungan' => Hubungan::class,
            'is_kontak_utama' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
