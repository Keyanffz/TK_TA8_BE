<?php

namespace App\Models;

use App\Enums\ModulIzin;
use App\Enums\Role;
use App\Enums\TargetPengumuman;
use Database\Factories\PengumumanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['judul', 'slug', 'isi', 'lampiran_path', 'target', 'is_publik', 'is_pinned', 'penulis_id', 'published_at'])]
class Pengumuman extends Model
{
    /** @use HasFactory<PengumumanFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pengumuman';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => TargetPengumuman::class,
            'is_publik' => 'boolean',
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeTerbit(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * Feed pengumuman (B6.10). Kepala Sekolah dan pemegang izin lihat pengumuman: semua, termasuk draft. Guru dan
     * wali murid: pengumuman terbit untuk semua, untuk role-nya, untuk kelas yang diampu / kelas anak,
     * dan untuk murid di kelasnya / anaknya; ditambah pengumuman tulisannya sendiri. Wali yang tidak punya anak aktif
     * lagi tidak melihat pengumuman untuk semua dan untuk wali murid (sama dengan penerima notifikasinya).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->punyaIzin(ModulIzin::Pengumuman)) {
            return;
        }

        $targetRole = $user->role === Role::Guru ? TargetPengumuman::Guru : TargetPengumuman::WaliMurid;
        $terimaUmum = $user->role === Role::Guru || User::query()->whereKey($user->id)->waliDenganAnakAktif()->exists();

        $query->where(fn (Builder $pengumuman) => $pengumuman
            ->where(fn (Builder $relevan) => $relevan
                ->terbit()
                ->where(fn (Builder $target) => $target
                    ->whereIn('target', $terimaUmum ? [TargetPengumuman::Semua, $targetRole] : [])
                    ->orWhere(fn (Builder $perKelas) => $perKelas
                        ->where('target', TargetPengumuman::Kelas)
                        ->whereHas('kelas', fn (Builder $kelas) => $user->role === Role::Guru
                            ? $kelas->diampuOleh($user)
                            : $kelas->whereHas('murid', fn (Builder $murid) => $murid->visibleTo($user))))
                    ->orWhere(fn (Builder $perMurid) => $perMurid
                        ->where('target', TargetPengumuman::Murid)
                        ->whereHas('murid', fn (Builder $murid) => $murid->visibleTo($user)))))
            ->orWhere('penulis_id', $user->id));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function penulis(): BelongsTo
    {
        return $this->belongsTo(User::class, 'penulis_id');
    }

    /**
     * @return BelongsToMany<Kelas, $this>
     */
    public function kelas(): BelongsToMany
    {
        return $this->belongsToMany(Kelas::class, 'pengumuman_kelas');
    }

    /**
     * @return BelongsToMany<Murid, $this>
     */
    public function murid(): BelongsToMany
    {
        return $this->belongsToMany(Murid::class, 'pengumuman_murid');
    }
}
