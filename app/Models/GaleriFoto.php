<?php

namespace App\Models;

use Database\Factories\GaleriFotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['galeri_album_id', 'path', 'caption', 'urutan'])]
class GaleriFoto extends Model
{
    /** @use HasFactory<GaleriFotoFactory> */
    use HasFactory;

    protected $table = 'galeri_foto';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GaleriAlbum, $this>
     */
    public function album(): BelongsTo
    {
        return $this->belongsTo(GaleriAlbum::class, 'galeri_album_id');
    }
}
