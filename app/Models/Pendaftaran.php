<?php

namespace App\Models;

use App\Enums\Hubungan;
use App\Enums\JenisKelamin;
use App\Enums\Role;
use App\Enums\StatusPendaftaran;
use App\Enums\Tingkat;
use Database\Factories\PendaftaranFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'kode', 'wali_murid_id', 'hubungan', 'tahun_ajaran_id', 'tingkat_tujuan', 'nama_lengkap', 'nama_panggilan',
    'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'nik', 'agama', 'alamat', 'nama_ayah', 'pekerjaan_ayah',
    'nama_ibu', 'pekerjaan_ibu', 'no_hp', 'status', 'catatan', 'diproses_oleh', 'diproses_at', 'murid_id',
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
            'status' => StatusPendaftaran::class,
            'diproses_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function pemroses(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diproses_oleh');
    }

    /**
     * @return BelongsTo<Murid, $this>
     */
    public function murid(): BelongsTo
    {
        return $this->belongsTo(Murid::class);
    }

    /**
     * @return HasMany<PendaftaranDokumen, $this>
     */
    public function dokumen(): HasMany
    {
        return $this->hasMany(PendaftaranDokumen::class);
    }
}
