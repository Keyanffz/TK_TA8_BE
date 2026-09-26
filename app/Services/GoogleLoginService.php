<?php

namespace App\Services;

use App\Enums\Perangkat;
use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Exceptions\AksesAkunDitolakException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\LayananBelumDikonfigurasiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Login Google hanya untuk wali murid (B6.8). Wali yang pertama kali login otomatis dibuatkan akun.
 */
class GoogleLoginService
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $verifier,
        private readonly AuthService $auth,
    ) {}

    /**
     * @return array{token: string, user: User, is_new: bool}
     *
     * @throws AksesAkunDitolakException
     * @throws BusinessRuleException
     * @throws LayananBelumDikonfigurasiException
     */
    public function login(string $idToken, Perangkat $perangkat): array
    {
        $profil = $this->verifier->verifikasi($idToken);

        if ($profil === null) {
            throw ValidationException::withMessages(['id_token' => ['Login Google tidak valid atau sudah kedaluwarsa. Silakan coba masuk lagi.']]);
        }

        if (! $profil['email_verified']) {
            throw ValidationException::withMessages(['id_token' => ['Email akun Google ini belum terverifikasi. Verifikasi email di Google lalu coba lagi.']]);
        }

        $user = User::query()->where('google_id', $profil['sub'])->first()
            ?? User::query()->where('email', $profil['email'])->first();

        if ($user !== null && $user->role !== Role::WaliMurid) {
            throw new BusinessRuleException('Email ini terdaftar sebagai akun guru atau Kepala Sekolah. Gunakan login email & password.');
        }

        $baru = $user === null;

        if ($user === null) {
            $user = $this->buatWaliMurid($profil);
        } elseif ($user->google_id === null) {
            $user->forceFill(['google_id' => $profil['sub']])->save();
        }

        $this->auth->pastikanAktif($user);

        return ['token' => $this->auth->buatToken($user, $perangkat), 'user' => $user, 'is_new' => $baru];
    }

    /**
     * @param  array{sub: string, email: string, name: string, email_verified: bool}  $profil
     */
    private function buatWaliMurid(array $profil): User
    {
        return DB::transaction(function () use ($profil): User {
            $user = User::query()->create([
                'name' => $profil['name'],
                'email' => $profil['email'],
                'google_id' => $profil['sub'],
                'role' => Role::WaliMurid,
                'status' => StatusAkun::Aktif,
                'email_verified_at' => now(),
            ]);
            $user->waliMurid()->create(['profil_lengkap' => false]);

            return $user;
        });
    }
}
