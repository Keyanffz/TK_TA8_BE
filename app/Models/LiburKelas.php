<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Libur absensi murid satu kelas, satu tanggal atau rentang (`tanggal_mulai` sampai `tanggal_selesai`, keduanya ikut).
 */
#[Fillable(['kelas_id', 'tanggal_mulai', 'tanggal_selesai', 'keterangan'])]
class LiburKelas extends Model
{
    protected $table = 'libur_kelas';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
        ];
    }

    public function mencakup(CarbonInterface $tanggal): bool
    {
        $hari = $tanggal->toDateString();

        return $hari >= $this->tanggal_mulai->toDateString() && $hari <= $this->tanggal_selesai->toDateString();
    }

    /**
     * @return BelongsTo<Kelas, $this>
     */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }
}
