<?php

namespace App\Models;

use App\Enums\StatusMurid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu perubahan status murid (A2.14). `diubah_oleh` null untuk perubahan dari akun yang sudah dihapus.
 */
#[Fillable(['murid_id', 'status_lama', 'status_baru', 'tanggal', 'tahun_lulus', 'sekolah_tujuan', 'keterangan', 'diubah_oleh'])]
class RiwayatStatusMurid extends Model
{
    protected $table = 'riwayat_status_murid';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_lama' => StatusMurid::class,
            'status_baru' => StatusMurid::class,
            'tanggal' => 'date',
            'tahun_lulus' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function pengubah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diubah_oleh');
    }
}
