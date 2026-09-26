<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\StatusRapor;
use Database\Factories\RaporFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'murid_id', 'kelas_id', 'tahun_ajaran_id', 'semester', 'tinggi_badan', 'berat_badan', 'catatan_guru',
    'status', 'catatan_revisi', 'dibuat_oleh', 'diajukan_at', 'disetujui_oleh', 'terbit_at',
])]
class Rapor extends Model
{
    /** @use HasFactory<RaporFactory> */
    use HasFactory;

    protected $table = 'rapor';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'tinggi_badan' => 'decimal:1',
            'berat_badan' => 'decimal:1',
            'status' => StatusRapor::class,
            'diajukan_at' => 'datetime',
            'terbit_at' => 'datetime',
        ];
    }

    /**
     * Kepala Sekolah: semua. Guru: rapor kelas yang diampu pada tahun ajaran aktif.
     * Wali murid: rapor anaknya yang sudah terbit (B4).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            Role::SuperAdmin => null,
            Role::Guru => $query->whereHas('kelas', fn (Builder $kelas) => $kelas->diampuOleh($user)),
            Role::WaliMurid => $query
                ->where('status', StatusRapor::Terbit)
                ->whereHas('murid', fn (Builder $murid) => $murid->visibleTo($user)),
        };
    }

    /**
     * @return BelongsTo<Murid, $this>
     */
    public function murid(): BelongsTo
    {
        return $this->belongsTo(Murid::class);
    }

    /**
     * @return BelongsTo<Kelas, $this>
     */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
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
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(Guru::class, 'dibuat_oleh');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    /**
     * @return HasMany<RaporDetail, $this>
     */
    public function detail(): HasMany
    {
        return $this->hasMany(RaporDetail::class);
    }
}
