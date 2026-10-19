<?php

namespace App\Models;

use App\Enums\JenisKelamin;
use App\Enums\ModulIzin;
use App\Enums\Role;
use App\Enums\StatusMurid;
use Carbon\CarbonInterface;
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
    'tanggal_masuk', 'tanggal_keluar', 'tahun_lulus', 'sekolah_tujuan', 'keterangan_status',
])]
class Murid extends Model
{
    /** @use HasFactory<MuridFactory> */
    use HasFactory, SoftDeletes;

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
            'tahun_lulus' => 'integer',
        ];
    }

    /**
     * Password awal akun wali murid: tanggal lahir anak dengan format DDMMYYYY.
     */
    public function passwordAwalWali(): string
    {
        return self::passwordAwalDari($this->tanggal_lahir);
    }

    public static function passwordAwalDari(CarbonInterface $tanggalLahir): string
    {
        return $tanggalLahir->format('dmY');
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
     * Daftar dan detail murid (`GET /murid`): pemegang izin lihat data murid melihat semua murid, termasuk alumni;
     * selain itu sama dengan `visibleTo`. Scope lain (tagihan, rapor, pengumuman) tetap memakai `visibleTo`, supaya
     * izin data murid tidak ikut membuka data modul lain.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDataMuridUntuk(Builder $query, User $user): void
    {
        if (! $user->punyaIzin(ModulIzin::DataMurid)) {
            $query->visibleTo($user);
        }
    }

    /**
     * Relasi yang ditampilkan `MuridDetailResource`: kelas aktif, wali yang tertaut, dan riwayat status.
     */
    public function muatDetail(): static
    {
        return $this->load(['kelasAktif', 'waliMurid.user', 'riwayatStatus.pengubah']);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCari(Builder $query, ?string $kata): void
    {
        if ($kata === null) {
            return;
        }

        $query->where(fn (Builder $murid) => $murid
            ->where('nama_lengkap', 'like', "%{$kata}%")
            ->orWhere('nama_panggilan', 'like', "%{$kata}%")
            ->orWhere('nis', 'like', "%{$kata}%")
            ->orWhere('nisn', 'like', "%{$kata}%"));
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
     * Terbaru dulu.
     *
     * @return HasMany<RiwayatStatusMurid, $this>
     */
    public function riwayatStatus(): HasMany
    {
        return $this->hasMany(RiwayatStatusMurid::class)->latest('tanggal')->latest('id');
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
