<?php

namespace App\Models;

use Database\Factories\GaleriAlbumFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['judul', 'slug', 'deskripsi', 'cover_path', 'tanggal', 'is_publik'])]
class GaleriAlbum extends Model
{
    /** @use HasFactory<GaleriAlbumFactory> */
    use HasFactory;

    protected $table = 'galeri_album';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'is_publik' => 'boolean',
        ];
    }

    /**
     * @return HasMany<GaleriFoto, $this>
     */
    public function foto(): HasMany
    {
        return $this->hasMany(GaleriFoto::class)->orderBy('urutan');
    }

    /**
     * Foto pertama menurut urutan, dipakai sebagai sampul kalau album tidak punya `cover_path`.
     *
     * @return HasOne<GaleriFoto, $this>
     */
    public function fotoPertama(): HasOne
    {
        return $this->hasOne(GaleriFoto::class)->oldestOfMany('urutan');
    }
}
