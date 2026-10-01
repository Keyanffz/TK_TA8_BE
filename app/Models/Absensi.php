<?php

namespace App\Models;

use App\Enums\JenisAbsensi;
use App\Enums\Role;
use App\Enums\StatusAbsensi;
use Database\Factories\AbsensiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris per peserta, tanggal, dan jenis (masuk atau pulang). `status` hanya diisi untuk absen masuk.
 * Baris `tidak_hadir` dibuat scheduler tanpa waktu, koordinat, dan foto.
 */
#[Fillable([
    'user_id', 'tanggal', 'jenis', 'status', 'waktu', 'latitude', 'longitude', 'akurasi_meter', 'jarak_meter',
    'foto_path', 'catatan_koreksi', 'dikoreksi_oleh', 'dikoreksi_at',
])]
class Absensi extends Model
{
    /** @use HasFactory<AbsensiFactory> */
    use HasFactory;

    protected $table = 'absensi';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'jenis' => JenisAbsensi::class,
            'status' => StatusAbsensi::class,
            'waktu' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'akurasi_meter' => 'integer',
            'jarak_meter' => 'integer',
            'dikoreksi_at' => 'datetime',
        ];
    }

    /**
     * Kepala Sekolah melihat absensi semua peserta, guru hanya miliknya.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->role !== Role::SuperAdmin) {
            $query->where('user_id', $user->id);
        }
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeMasuk(Builder $query): void
    {
        $query->where('jenis', JenisAbsensi::Masuk);
    }

    /**
     * Baris tidak hadir buatan scheduler yang belum pernah dikoreksi: tanpa waktu dan foto. Absen sungguhan
     * yang dikoreksi menjadi tidak hadir tetap punya waktu, jadi tidak termasuk.
     *
     * @param  Builder<self>  $query
     */
    public function scopeTidakHadirOtomatis(Builder $query): void
    {
        $query->where('jenis', JenisAbsensi::Masuk)
            ->where('status', StatusAbsensi::TidakHadir)
            ->whereNull('waktu')
            ->whereNull('foto_path')
            ->whereNull('dikoreksi_at');
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
    public function pengoreksi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikoreksi_oleh');
    }
}
