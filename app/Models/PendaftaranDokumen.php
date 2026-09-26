<?php

namespace App\Models;

use App\Enums\JenisDokumen;
use Database\Factories\PendaftaranDokumenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pendaftaran_id', 'jenis', 'path'])]
class PendaftaranDokumen extends Model
{
    /** @use HasFactory<PendaftaranDokumenFactory> */
    use HasFactory;

    protected $table = 'pendaftaran_dokumen';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis' => JenisDokumen::class,
        ];
    }

    /**
     * @return BelongsTo<Pendaftaran, $this>
     */
    public function pendaftaran(): BelongsTo
    {
        return $this->belongsTo(Pendaftaran::class);
    }
}
