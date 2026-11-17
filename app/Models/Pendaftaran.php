<?php

namespace App\Models;

use App\Enums\Hubungan;
use App\Enums\JenisKelamin;
use App\Enums\Role;
use App\Enums\StatusPendaftaran;
use App\Enums\Tingkat;
use App\Support\FormulirMurid;
use Database\Factories\PendaftaranFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'kode', 'wali_murid_id', 'hubungan', 'tahun_ajaran_id', 'tingkat_tujuan', 'nama_lengkap', 'nama_panggilan',
    'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'nik', 'agama', 'alamat', 'anak_ke', 'status',
    'status_sebelum_mundur', 'catatan', 'diproses_oleh', 'diproses_at', 'murid_id',
    ...FormulirMurid::KOLOM_ANAK, ...FormulirMurid::KOLOM_BERKAS,
])]
class Pendaftaran extends Model
{
    /** @use HasFactory<PendaftaranFactory> */
    use HasFactory;

    protected $table = 'pendaftaran';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hubungan' => Hubungan::class,
            'tingkat_tujuan' => Tingkat::class,
            'jenis_kelamin' => JenisKelamin::class,
            'tanggal_lahir' => 'date',
            'anak_ke' => 'integer',
            'status' => StatusPendaftaran::class,
            'status_sebelum_mundur' => StatusPendaftaran::class,
            'diproses_at' => 'datetime',
            ...FormulirMurid::CAST_ANAK,
            'kk_diterima_at' => 'datetime',
            'akta_diterima_at' => 'datetime',
        ];
    }

    /**
     * Kepala Sekolah: semua. Wali murid: pendaftaran miliknya. Guru tidak punya akses PPDB.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            Role::SuperAdmin => null,
            Role::WaliMurid => $query->whereHas('waliMurid', fn (Builder $wali) => $wali->where('user_id', $user->id)),
            Role::Guru => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @return BelongsTo<WaliMurid, $this>
     */
    public function waliMurid(): BelongsTo
    {
        return $this->belongsTo(WaliMurid::class);
    }

    /**
     * @return BelongsTo<TahunAjaran, $this>
     */
    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    /**
     * Murid yang dihapus karena pendaftar mengundurkan diri setelah diterima tidak ikut termuat.
     *
     * @return BelongsTo<Murid, $this>
     */
    public function murid(): BelongsTo
    {
        return $this->belongsTo(Murid::class);
    }

    /**
     * @return HasMany<OrangTua, $this>
     */
    public function orangTua(): HasMany
    {
        return $this->hasMany(OrangTua::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function kkDiterimaOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kk_diterima_oleh');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function aktaDiterimaOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'akta_diterima_oleh');
    }
}
