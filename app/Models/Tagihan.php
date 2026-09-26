<?php

namespace App\Models;

use App\Enums\StatusTagihan;
use Database\Factories\TagihanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'kode', 'murid_id', 'jenis_tagihan_id', 'tahun_ajaran_id', 'periode', 'nominal', 'potongan',
    'total', 'jatuh_tempo', 'status', 'lunas_at', 'dibuat_oleh', 'catatan',
])]
class Tagihan extends Model
{
    /** @use HasFactory<TagihanFactory> */
    use HasFactory;

    protected $table = 'tagihan';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'periode' => 'date',
            'nominal' => 'integer',
            'potongan' => 'integer',
            'total' => 'integer',
            'jatuh_tempo' => 'date',
            'status' => StatusTagihan::class,
            'lunas_at' => 'datetime',
        ];
    }

    /**
     * Petugas keuangan: semua tagihan. Guru lain dan wali murid: tagihan murid yang terlihat olehnya.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->bisaKelolaKeuangan()) {
            return;
        }

        $query->whereHas('murid', fn (Builder $murid) => $murid->visibleTo($user));
    }

    /**
     * Nama tagihan untuk pesan dan kwitansi: "SPP Oktober 2026" untuk tagihan bulanan, nama jenisnya saja
     * untuk tagihan sekali bayar. Butuh relasi `jenisTagihan`.
     */
    public function label(): string
    {
        return $this->periode === null
            ? $this->jenisTagihan->nama
            : $this->jenisTagihan->nama.' '.$this->periode->translatedFormat('F Y');
    }

    /**
     * @return BelongsTo<Murid, $this>
     */
    public function murid(): BelongsTo
    {
        return $this->belongsTo(Murid::class);
    }

    /**
     * @return BelongsTo<JenisTagihan, $this>
     */
    public function jenisTagihan(): BelongsTo
    {
        return $this->belongsTo(JenisTagihan::class);
    }

    /**
     * @return BelongsTo<TahunAjaran, $this>
     */
    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    /**
     * @return HasMany<Pembayaran, $this>
     */
    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class);
    }
}
