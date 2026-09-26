<?php

namespace App\Models;

use Database\Factories\KegiatanFotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kegiatan_kelas_id', 'path', 'caption', 'urutan'])]
class KegiatanFoto extends Model
{
    /** @use HasFactory<KegiatanFotoFactory> */
    use HasFactory;

    protected $table = 'kegiatan_foto';

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
     * @return BelongsTo<KegiatanKelas, $this>
     */
    public function kegiatan(): BelongsTo
    {
        return $this->belongsTo(KegiatanKelas::class, 'kegiatan_kelas_id');
    }
}
