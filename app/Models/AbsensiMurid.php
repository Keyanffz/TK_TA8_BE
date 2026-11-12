<?php

namespace App\Models;

use App\Enums\StatusAbsensiMurid;
use Database\Factories\AbsensiMuridFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris per murid per tanggal. `kelas_id` adalah kelas tempat absensi itu dicatat, supaya rekap kelas tetap
 * benar walaupun murid kemudian pindah kelas.
 */
#[Fillable(['murid_id', 'kelas_id', 'tanggal', 'status', 'keterangan', 'dicatat_oleh'])]
class AbsensiMurid extends Model
{
    /** @use HasFactory<AbsensiMuridFactory> */
    use HasFactory;

    protected $table = 'absensi_murid';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'status' => StatusAbsensiMurid::class,
        ];
    }

    /**
     * @return BelongsTo<Murid, $this>
     */
    public function murid(): BelongsTo
    {
        return $this->belongsTo(Murid::class);
    }

    /**
     * @return BelongsTo<Kelas, $this>
     */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }
}
