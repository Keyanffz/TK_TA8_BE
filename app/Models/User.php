<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

#[Fillable(['name', 'email', 'username', 'password', 'wajib_ganti_password', 'role', 'status', 'no_hp', 'avatar_path', 'email_verified_at', 'last_login_at'])]
#[Hidden(['password', 'remember_token', 'google_sub'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Nilai bawaan kolom yang sama dengan database, supaya model yang baru dibuat langsung punya nilainya.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'wajib_ganti_password' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'wajib_ganti_password' => 'boolean',
            'role' => Role::class,
            'status' => StatusAkun::class,
        ];
    }

    /**
     * Email dibandingkan tanpa membedakan huruf besar (login Google, cek unik), jadi selalu disimpan huruf kecil.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $email): ?string => $email === null ? null : Str::lower(trim($email)));
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
     * Akun wali murid selalu punya profil wali (dibuat bersamaan dengan akunnya).
     */
    public function profilWaliMurid(): WaliMurid
    {
        return $this->waliMurid ?? throw new LogicException("Akun #{$this->id} tidak punya profil wali murid.");
    }

    /**
     * Profil guru milik guru atau Kepala Sekolah (A2.1), dipakai sebagai pembuat kegiatan kelas dan rapor.
     */
    public function profilGuru(): Guru
    {
        return $this->guru ?? throw new LogicException("Akun #{$this->id} tidak punya profil guru.");
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
