<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\KegiatanKelasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kelas_id', 'guru_id', 'tanggal', 'tema', 'judul', 'deskripsi'])]
class KegiatanKelas extends Model
{
    /** @use HasFactory<KegiatanKelasFactory> */
    use HasFactory;

    protected $table = 'kegiatan_kelas';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
        ];
    }

    /**
     * Kepala Sekolah: semua. Guru: kegiatan kelas yang diampu pada tahun ajaran aktif.
     * Wali murid: kegiatan kelas yang pernah atau sedang diikuti anaknya.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            Role::SuperAdmin => null,
            Role::Guru => $query->whereHas('kelas', fn (Builder $kelas) => $kelas->diampuOleh($user)),
            Role::WaliMurid => $query->whereHas('kelas.murid', fn (Builder $murid) => $murid->visibleTo($user)),
        };
    }

    /**
     * @return BelongsTo<Kelas, $this>
     */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }

    /**
     * @return BelongsTo<Guru, $this>
     */
    public function guru(): BelongsTo
    {
        return $this->belongsTo(Guru::class);
    }

    /**
     * @return HasMany<KegiatanFoto, $this>
     */
    public function foto(): HasMany
    {
        return $this->hasMany(KegiatanFoto::class)->orderBy('urutan');
    }
}
