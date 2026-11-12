<?php

namespace App\Models;

use App\Enums\JenisAbsensi;
use Database\Factories\AksesAbsenSusulanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Izin sekali pakai dari Kepala Sekolah supaya satu peserta bisa absen sendiri (swafoto, tanpa pemeriksaan lokasi
 * dan jam) untuk satu tanggal dan satu jenis, misalnya saat wifi sekolah bermasalah. Berlaku sampai akhir hari
 * akses diberikan.
 */
#[Fillable(['user_id', 'tanggal', 'jenis', 'catatan', 'diberikan_oleh', 'berlaku_sampai', 'dipakai_at'])]
class AksesAbsenSusulan extends Model
{
    /** @use HasFactory<AksesAbsenSusulanFactory> */
    use HasFactory;

    protected $table = 'akses_absen_susulan';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'jenis' => JenisAbsensi::class,
            'berlaku_sampai' => 'datetime',
            'dipakai_at' => 'datetime',
        ];
    }

    /**
     * Belum dipakai dan belum kedaluwarsa.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAktif(Builder $query): void
    {
        $query->whereNull('dipakai_at')->where('berlaku_sampai', '>=', now());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pemberi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diberikan_oleh');
    }
}
