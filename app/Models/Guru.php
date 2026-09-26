<?php

namespace App\Models;

use App\Enums\JenisKelamin;
use App\Enums\Role;
use Database\Factories\GuruFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'nip', 'nuptk', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'alamat',
    'pendidikan_terakhir', 'jabatan', 'foto_path', 'bisa_kelola_keuangan', 'tampil_di_landing',
    'disetujui_oleh', 'disetujui_at', 'alasan_penolakan',
])]
class Guru extends Model
{
    /** @use HasFactory<GuruFactory> */
    use HasFactory;

    public const JABATAN_KEPALA_SEKOLAH = 'Kepala Sekolah';

    protected $table = 'guru';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis_kelamin' => JenisKelamin::class,
            'tanggal_lahir' => 'date',
            'bisa_kelola_keuangan' => 'boolean',
            'tampil_di_landing' => 'boolean',
            'disetujui_at' => 'datetime',
        ];
    }

    /**
     * Profil guru milik Kepala Sekolah (A2.1): tidak tampil di `GET /guru`, tidak bisa dinonaktifkan,
     * dan izin keuangannya tidak bisa diubah.
     */
    public function milikKepalaSekolah(): bool
    {
        return $this->user->role === Role::SuperAdmin;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeBukanKepalaSekolah(Builder $query): void
    {
        $query->whereHas('user', fn (Builder $user) => $user->where('role', Role::Guru));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCari(Builder $query, ?string $kata): void
    {
        if ($kata === null) {
            return;
        }

        $query->where(fn (Builder $guru) => $guru
            ->where('nip', 'like', "%{$kata}%")
            ->orWhere('nuptk', 'like', "%{$kata}%")
            ->orWhereHas('user', fn (Builder $user) => $user
                ->where('name', 'like', "%{$kata}%")
                ->orWhere('email', 'like', "%{$kata}%")));
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
    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    /**
     * @return HasMany<Kelas, $this>
     */
    public function kelasWali(): HasMany
    {
        return $this->hasMany(Kelas::class, 'wali_kelas_id');
    }

    /**
     * @return HasMany<Kelas, $this>
     */
    public function kelasPendamping(): HasMany
    {
        return $this->hasMany(Kelas::class, 'guru_pendamping_id');
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
        return $this->hasMany(Rapor::class, 'dibuat_oleh');
    }
}
