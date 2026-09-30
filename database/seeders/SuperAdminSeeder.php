<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Models\Guru;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Membuat akun Kepala Sekolah beserta profil gurunya (A2.1). Aman dijalankan ulang:
 * akun yang sudah ada tidak diubah, termasuk password-nya.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'name' => config('superadmin.name'),
            'email' => Str::lower(trim((string) config('superadmin.email'))),
            'password' => config('superadmin.password'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException('Isi SUPERADMIN_NAME, SUPERADMIN_EMAIL, dan SUPERADMIN_PASSWORD (minimal 8 karakter, berisi huruf dan angka) di .env. '.implode(' ', $validator->errors()->all()));
        }

        $pemilikEmail = User::query()->where('email', $data['email'])->first();

        if ($pemilikEmail !== null && $pemilikEmail->role !== Role::SuperAdmin) {
            throw new RuntimeException("Email {$data['email']} sudah dipakai akun {$pemilikEmail->role->label()}. Pakai email lain untuk Kepala Sekolah.");
        }

        $adaKepalaSekolahLain = User::query()
            ->where('role', Role::SuperAdmin)
            ->where('status', StatusAkun::Aktif)
            ->where('email', '!=', $data['email'])
            ->exists();

        if ($adaKepalaSekolahLain) {
            throw new RuntimeException('Sudah ada akun Kepala Sekolah aktif dengan email lain. Hanya boleh ada satu; nonaktifkan akun lama lebih dulu.');
        }

        DB::transaction(function () use ($data): void {
            $user = User::query()->firstOrCreate(['email' => $data['email']], [
                'name' => $data['name'],
                'password' => $data['password'],
                'role' => Role::SuperAdmin,
                'status' => StatusAkun::Aktif,
                'email_verified_at' => now(),
            ]);

            Guru::query()->firstOrCreate(['user_id' => $user->id], [
                'jabatan' => Guru::JABATAN_KEPALA_SEKOLAH,
                'bisa_kelola_keuangan' => true,
            ]);
        });
    }
}
