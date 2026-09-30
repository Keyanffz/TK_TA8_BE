<?php

namespace App\Services;

use App\Enums\Perangkat;
use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Exceptions\AksesAkunDitolakException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    private const ROLE_STAFF = [Role::SuperAdmin, Role::Guru];

    public function __construct(private readonly MediaService $media) {}

    /**
     * Status akun baru dicek setelah password terbukti benar, supaya orang yang tidak tahu
     * password tidak bisa mengetahui status akun seseorang.
     *
     * @return array{token: string, user: User}
     *
     * @throws AksesAkunDitolakException
     */
    public function loginStaff(string $email, string $password, Perangkat $perangkat): array
    {
        $user = User::query()->with('guru')->where('email', $email)->whereIn('role', self::ROLE_STAFF)->first();

        if (! $this->passwordCocok($user, $password)) {
            throw ValidationException::withMessages(['email' => ['Email atau password salah.']]);
        }

        $this->pastikanAktif($user);

        return ['token' => $this->buatToken($user, $perangkat), 'user' => $user];
    }

    /**
     * Login wali murid dengan NIS anak sebagai username. Akun yang masih memakai password awal tetap mendapat
     * token, tetapi hanya bisa mengganti password sampai `wajib_ganti_password` bernilai false.
     *
     * @return array{token: string, user: User}
     *
     * @throws AksesAkunDitolakException
     */
    public function loginWali(string $username, string $password, Perangkat $perangkat): array
    {
        $user = User::query()->where('username', $username)->where('role', Role::WaliMurid)->first();

        if (! $this->passwordCocok($user, $password)) {
            throw ValidationException::withMessages(['username' => ['NIS atau password salah.']]);
        }

        $this->pastikanAktif($user);

        return ['token' => $this->buatToken($user, $perangkat), 'user' => $user];
    }

    /**
     * @throws AksesAkunDitolakException
     */
    public function pastikanAktif(User $user): void
    {
        if ($user->status !== StatusAkun::Aktif) {
            throw AksesAkunDitolakException::untuk($user->status, $user->guru?->alasan_penolakan);
        }
    }

    public function buatToken(User $user, Perangkat $perangkat): string
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return $user->createToken($perangkat->value)->plainTextToken;
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    /**
     * Hanya akun Kepala Sekolah dan guru yang dikirimi tautan; wali murid tidak punya email dan meminta reset
     * password ke sekolah. Hasilnya tidak dibedakan ke klien supaya keberadaan email tidak bocor.
     */
    public function kirimTautanResetPassword(string $email): void
    {
        if ($this->akunPassword($email) !== null) {
            Password::broker()->sendResetLink(['email' => $email]);
        }
    }

    public function resetPassword(string $token, string $email, string $passwordBaru): void
    {
        $status = $this->akunPassword($email) === null
            ? Password::INVALID_USER
            : Password::broker()->reset(
                ['token' => $token, 'email' => $email, 'password' => $passwordBaru],
                function (User $user, string $password): void {
                    $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                    $user->tokens()->delete();
                },
            );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['Tautan reset password tidak valid atau sudah kedaluwarsa. Minta tautan baru dari halaman lupa password.'],
            ]);
        }
    }

    /**
     * @param  array{name: string, no_hp?: string|null}  $data
     */
    public function perbaruiProfil(User $user, array $data, ?UploadedFile $avatar): User
    {
        $avatarLama = $user->avatar_path;

        DB::transaction(function () use ($user, $data, $avatar): void {
            $user->fill([
                'name' => $data['name'],
                'no_hp' => $data['no_hp'] ?? $user->no_hp,
            ]);

            if ($avatar !== null) {
                $user->avatar_path = $this->media->simpanGambar($avatar, MediaService::DISK_PUBLIK, 'avatar');
            }

            $user->save();
        });

        if ($avatar !== null) {
            $this->media->hapus($avatarLama, MediaService::DISK_PUBLIK);
        }

        return $user;
    }

    /**
     * Sesi lain (perangkat lain) ikut dikeluarkan; sesi yang sedang dipakai tetap berlaku.
     */
    public function gantiPassword(User $user, string $passwordBaru): void
    {
        $user->forceFill(['password' => $passwordBaru, 'wajib_ganti_password' => false])->save();

        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();
    }

    /**
     * Akun yang tidak ditemukan (termasuk role yang bukan milik endpoint login) atau tanpa password tetap melewati
     * Hash::check terhadap hash palsu, supaya waktu respons tidak membedakan akun ada dan tidak ada.
     *
     * @phpstan-assert-if-true User $user
     */
    private function passwordCocok(?User $user, string $password): bool
    {
        $hashAkun = $user?->password;
        $cocok = Hash::check($password, $hashAkun ?? $this->hashPalsu());

        return $cocok && $hashAkun !== null;
    }

    /**
     * Dibuat sekali lalu disimpan di cache: membuat hash di setiap request justru membuat jalur akun tidak ada
     * dua kali lebih lambat. Cost ada di kunci cache, jadi hash ikut dibuat ulang kalau BCRYPT_ROUNDS berubah.
     */
    private function hashPalsu(): string
    {
        return Cache::rememberForever(
            'auth:hash-palsu:'.Hash::getDefaultDriver().':'.config('hashing.bcrypt.rounds'),
            fn (): string => Hash::make(Str::random(40)),
        );
    }

    private function akunPassword(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->whereIn('role', self::ROLE_STAFF)
            ->whereNotNull('password')
            ->first();
    }
}
