<?php

namespace App\Models;

use App\Enums\PeriodeTagihan;
use App\Enums\Tingkat;
use Database\Factories\JenisTagihanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tahun_ajaran_id', 'nama', 'deskripsi', 'nominal', 'periode', 'tingkat', 'is_aktif'])]
class JenisTagihan extends Model
{
    /** @use HasFactory<JenisTagihanFactory> */
    use HasFactory;

    protected $table = 'jenis_tagihan';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nominal' => 'integer',
            'periode' => PeriodeTagihan::class,
            'tingkat' => Tingkat::class,
            'is_aktif' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TahunAjaran, $this>
     */
    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    /**
     * @return HasMany<Tagihan, $this>
     */
    public function tagihan(): HasMany
    {
        return $this->hasMany(Tagihan::class);
    }

    /**
     * @return HasMany<Keringanan, $this>
     */
    public function keringanan(): HasMany
    {
        return $this->hasMany(Keringanan::class);
    }
}
