<?php

namespace App\Models;

use App\Enums\JenisKelamin;
use App\Enums\Role;
use App\Enums\StatusMurid;
use Database\Factories\MuridFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'nis', 'nisn', 'nik', 'nama_lengkap', 'nama_panggilan', 'jenis_kelamin', 'tempat_lahir',
    'tanggal_lahir', 'agama', 'alamat', 'anak_ke', 'foto_path', 'catatan_khusus', 'status',
    'tanggal_masuk', 'tanggal_keluar', 'kode_tautan', 'kode_tautan_expired_at',
])]
class Murid extends Model
{
    /** @use HasFactory<MuridFactory> */
    use HasFactory, SoftDeletes;

    /** Huruf besar dan angka tanpa karakter yang mudah tertukar (0 O 1 I L), sesuai B6.6. */
    public const KARAKTER_KODE_TAUTAN = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const PANJANG_KODE_TAUTAN = 8;

    protected $table = 'murid';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis_kelamin' => JenisKelamin::class,
            'tanggal_lahir' => 'date',
            'anak_ke' => 'integer',
            'status' => StatusMurid::class,
            'tanggal_masuk' => 'date',
            'tanggal_keluar' => 'date',
            'kode_tautan_expired_at' => 'datetime',
        ];
    }

    /**
     * Kepala Sekolah: semua murid. Guru: murid di kelas yang diampu pada tahun ajaran aktif.
     * Wali murid: anaknya sendiri.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            Role::SuperAdmin => null,
            Role::Guru => $query->whereHas('kelas', fn (Builder $kelas) => $kelas->diampuOleh($user)),
            Role::WaliMurid => $query->whereHas('waliMurid', fn (Builder $wali) => $wali->where('user_id', $user->id)),
        };
    }

    /**
     * @return BelongsToMany<WaliMurid, $this, MuridWali>
     */
    public function waliMurid(): BelongsToMany
    {
        return $this->belongsToMany(WaliMurid::class, 'murid_wali')
            ->using(MuridWali::class)
            ->withPivot('hubungan', 'is_kontak_utama', 'created_at');
    }

    /**
     * @return BelongsToMany<Kelas, $this, KelasMurid>
     */
    public function kelas(): BelongsToMany
    {
        return $this->belongsToMany(Kelas::class, 'kelas_murid')
            ->using(KelasMurid::class)
            ->withPivot('id', 'status')
            ->withTimestamps();
    }

    /**
     * Kelas murid di tahun ajaran aktif (paling banyak satu, dijaga service penempatan).
     *
     * @return BelongsToMany<Kelas, $this, KelasMurid>
     */
    public function kelasAktif(): BelongsToMany
    {
        return $this->kelas()->whereHas('tahunAjaran', fn (Builder $tahunAjaran) => $tahunAjaran->where('is_aktif', true));
    }

    /**
     * @return HasMany<KelasMurid, $this>
     */
    public function kelasMurid(): HasMany
    {
        return $this->hasMany(KelasMurid::class);
    }

    /**
     * @return HasMany<Tagihan, $this>
     */
    public function tagihan(): HasMany
    {
        return $this->hasMany(Tagihan::class);
    }

    /**
     * @return HasMany<Keringanan, $this>
     */
    public function keringanan(): HasMany
    {
        return $this->hasMany(Keringanan::class);
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
        return $this->belongsToMany(Pengumuman::class, 'pengumuman_murid');
    }

    /**
     * @return HasOne<Pendaftaran, $this>
     */
    public function pendaftaran(): HasOne
    {
        return $this->hasOne(Pendaftaran::class);
    }
}
