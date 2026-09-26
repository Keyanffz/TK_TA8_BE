<?php

namespace App\Models;

use App\Enums\MetodeBayar;
use App\Enums\StatusPembayaran;
use Database\Factories\PembayaranFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'kode', 'tagihan_id', 'dibayar_oleh', 'metode', 'jumlah', 'tanggal_bayar', 'bukti_path',
    'bank_pengirim', 'nama_pengirim', 'status', 'alasan_penolakan', 'diverifikasi_oleh', 'diverifikasi_at',
])]
class Pembayaran extends Model
{
    /** @use HasFactory<PembayaranFactory> */
    use HasFactory;

    protected $table = 'pembayaran';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metode' => MetodeBayar::class,
            'jumlah' => 'integer',
            'tanggal_bayar' => 'date',
            'status' => StatusPembayaran::class,
            'diverifikasi_at' => 'datetime',
        ];
    }

    /**
     * Petugas keuangan: semua pembayaran. Lainnya: pembayaran dari tagihan yang terlihat olehnya
     * (guru melihat riwayat pembayaran di detail tagihan murid kelasnya).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->bisaKelolaKeuangan()) {
            return;
        }

        $query->whereHas('tagihan', fn (Builder $tagihan) => $tagihan->visibleTo($user));
    }

    /**
     * @return BelongsTo<Tagihan, $this>
     */
    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pembayar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibayar_oleh');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifikator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh');
    }
}
