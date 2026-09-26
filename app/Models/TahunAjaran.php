<?php

namespace App\Models;

use Database\Factories\TahunAjaranFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nama', 'tanggal_mulai', 'tanggal_selesai', 'semester_aktif', 'is_aktif'])]
class TahunAjaran extends Model
{
    /** @use HasFactory<TahunAjaranFactory> */
    use HasFactory;

    protected $table = 'tahun_ajaran';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'semester_aktif' => 'integer',
            'is_aktif' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAktif(Builder $query): void
    {
        $query->where('is_aktif', true);
    }

    /**
     * @return HasMany<Kelas, $this>
     */
    public function kelas(): HasMany
    {
        return $this->hasMany(Kelas::class);
    }

    /**
     * @return HasMany<JenisTagihan, $this>
     */
    public function jenisTagihan(): HasMany
    {
        return $this->hasMany(JenisTagihan::class);
    }

    /**
     * @return HasMany<Tagihan, $this>
     */
    public function tagihan(): HasMany
    {
        return $this->hasMany(Tagihan::class);
    }

    /**
     * @return HasMany<Rapor, $this>
     */
    public function rapor(): HasMany
    {
        return $this->hasMany(Rapor::class);
    }

    /**
     * @return HasMany<Pendaftaran, $this>
     */
    public function pendaftaran(): HasMany
    {
        return $this->hasMany(Pendaftaran::class);
    }
}
