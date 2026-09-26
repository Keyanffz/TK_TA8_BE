<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

#[Fillable(['name', 'email', 'password', 'google_id', 'role', 'status', 'no_hp', 'avatar_path', 'email_verified_at', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'status' => StatusAkun::class,
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeKepalaSekolahAktif(Builder $query): void
    {
        $query->where('role', Role::SuperAdmin)->where('status', StatusAkun::Aktif);
    }

    /**
     * Penerima notifikasi `pembayaran_masuk`: Kepala Sekolah dan guru berizin keuangan yang akunnya aktif.
     *
     * @param  Builder<self>  $query
     */
    public function scopePetugasKeuanganAktif(Builder $query): void
    {
        $query->where('status', StatusAkun::Aktif)
            ->where(fn (Builder $user) => $user
                ->where('role', Role::SuperAdmin)
                ->orWhere(fn (Builder $guru) => $guru
                    ->where('role', Role::Guru)
                    ->whereHas('guru', fn (Builder $profil) => $profil->where('bisa_kelola_keuangan', true))));
    }

    /**
     * @return HasOne<Guru, $this>
     */
    public function guru(): HasOne
    {
        return $this->hasOne(Guru::class);
    }

    /**
     * @return HasOne<WaliMurid, $this>
     */
    public function waliMurid(): HasOne
    {
        return $this->hasOne(WaliMurid::class);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Akun wali murid selalu punya profil wali (dibuat bersamaan saat login Google pertama).
     */
    public function profilWaliMurid(): WaliMurid
    {
        return $this->waliMurid ?? throw new LogicException("Akun {$this->email} tidak punya profil wali murid.");
    }

    /**
     * Petugas keuangan: Kepala Sekolah, atau guru yang diberi izin `bisa_kelola_keuangan`.
     */
    public function bisaKelolaKeuangan(): bool
    {
        return match ($this->role) {
            Role::SuperAdmin => true,
            Role::Guru => $this->guru?->bisa_kelola_keuangan === true,
            Role::WaliMurid => false,
        };
    }
}
