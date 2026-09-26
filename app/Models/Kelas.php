<?php

namespace App\Models;

use App\Enums\StatusKelasMurid;
use App\Enums\Tingkat;
use Database\Factories\KelasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tahun_ajaran_id', 'nama', 'tingkat', 'wali_kelas_id', 'guru_pendamping_id', 'kapasitas'])]
class Kelas extends Model
{
    /** @use HasFactory<KelasFactory> */
    use HasFactory;

    /** Sama dengan default kolom `kelas.kapasitas` (A4). */
    public const KAPASITAS_BAWAAN = 20;

    protected $table = 'kelas';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tingkat' => Tingkat::class,
            'kapasitas' => 'integer',
        ];
    }

    /**
     * Kelas di tahun ajaran aktif yang diampu user (sebagai wali kelas atau guru pendamping).
     *
     * @param  Builder<self>  $query
     */
    public function scopeDiampuOleh(Builder $query, User $user): void
    {
        $guruIds = Guru::query()->select('id')->where('user_id', $user->id);

        $query->whereHas('tahunAjaran', fn (Builder $tahunAjaran) => $tahunAjaran->where('is_aktif', true))
            ->where(fn (Builder $kelas) => $kelas
                ->whereIn('wali_kelas_id', $guruIds)
                ->orWhereIn('guru_pendamping_id', $guruIds));
    }

    /**
     * Relasi yang ditampilkan `KelasResource` di detail kelas, termasuk daftar murid urut nama.
     */
    public function muatDetail(): static
    {
        return $this->load([
            'tahunAjaran', 'waliKelas.user', 'guruPendamping.user',
            'murid' => fn (BelongsToMany $murid) => $murid->orderBy('nama_lengkap'),
        ])->loadCount('muridAktif');
    }

    /**
     * @return BelongsTo<TahunAjaran, $this>
     */
    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    /**
     * @return BelongsTo<Guru, $this>
     */
    public function waliKelas(): BelongsTo
    {
        return $this->belongsTo(Guru::class, 'wali_kelas_id');
    }

    /**
     * @return BelongsTo<Guru, $this>
     */
    public function guruPendamping(): BelongsTo
    {
        return $this->belongsTo(Guru::class, 'guru_pendamping_id');
    }

    /**
     * @return BelongsToMany<Murid, $this, KelasMurid>
     */
    public function murid(): BelongsToMany
    {
        return $this->belongsToMany(Murid::class, 'kelas_murid')
            ->using(KelasMurid::class)
            ->withPivot('id', 'status')
            ->withTimestamps();
    }

    /**
     * Murid yang penempatannya masih berjalan; dipakai untuk jumlah murid dan batas kapasitas.
     *
     * @return BelongsToMany<Murid, $this, KelasMurid>
     */
    public function muridAktif(): BelongsToMany
    {
        return $this->murid()->wherePivot('status', StatusKelasMurid::Aktif);
    }

    /**
     * @return HasMany<KelasMurid, $this>
     */
    public function kelasMurid(): HasMany
    {
        return $this->hasMany(KelasMurid::class);
    }

    /**
     * @return HasMany<KegiatanKelas, $this>
     */
    public function kegiatan(): HasMany
    {
        return $this->hasMany(KegiatanKelas::class);
    }

    /**
     * @return HasMany<Rapor, $this>
     */
    public function rapor(): HasMany
    {
        return $this->hasMany(Rapor::class);
    }

    /**
     * @return BelongsToMany<Pengumuman, $this>
     */
    public function pengumuman(): BelongsToMany
    {
        return $this->belongsToMany(Pengumuman::class, 'pengumuman_kelas');
    }
}
