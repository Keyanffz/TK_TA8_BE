<?php

namespace App\Models;

use Database\Factories\ElemenPenilaianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kode', 'nama', 'deskripsi', 'urutan', 'is_aktif'])]
class ElemenPenilaian extends Model
{
    /** @use HasFactory<ElemenPenilaianFactory> */
    use HasFactory;

    protected $table = 'elemen_penilaian';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'is_aktif' => 'boolean',
        ];
    }

    /**
     * @return HasMany<RaporDetail, $this>
     */
    public function raporDetail(): HasMany
    {
        return $this->hasMany(RaporDetail::class);
    }
}
