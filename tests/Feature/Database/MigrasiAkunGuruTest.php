<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Menjalankan ulang migration data di atas akun dengan status lama, yang ditulis langsung ke tabel karena enum
 * StatusAkun sudah tidak mengenal `pending` dan `ditolak`.
 */
function jalankanMigrasiAkunGuru(): void
{
    (require database_path('migrations/2026_09_30_100001_nonaktifkan_akun_guru_pending_dan_ditolak.php'))->up();
}

function sisipkanAkunLama(string $email, string $role, string $status): int
{
    return DB::table('users')->insertGetId([
        'name' => 'Akun Lama',
        'email' => $email,
        'password' => Hash::make('guru2026'),
        'role' => $role,
        'status' => $status,
        'remember_token' => Str::random(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('menonaktifkan akun guru pending dan ditolak tanpa menghapusnya', function () {
    $pending = sisipkanAkunLama('fitri.handayani@guru.tkta8.test', 'guru', 'pending');
    $ditolak = sisipkanAkunLama('ahmad.fauzi@guru.tkta8.test', 'guru', 'ditolak');
    $aktif = sisipkanAkunLama('nur.aini@guru.tkta8.test', 'guru', 'aktif');
    DB::table('guru')->insert(['user_id' => $pending, 'jabatan' => 'Guru', 'created_at' => now(), 'updated_at' => now()]);
    User::query()->findOrFail($pending)->createToken('web');

    jalankanMigrasiAkunGuru();

    expect(DB::table('users')->whereIn('id', [$pending, $ditolak])->pluck('status')->all())->toBe(['nonaktif', 'nonaktif'])
        ->and(DB::table('users')->where('id', $aktif)->value('status'))->toBe('aktif')
        ->and(DB::table('guru')->where('user_id', $pending)->exists())->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $pending)->exists())->toBeFalse();
});

it('mengosongkan password guru tetapi mempertahankan password Kepala Sekolah', function () {
    $guru = sisipkanAkunLama('nur.aini@guru.tkta8.test', 'guru', 'aktif');
    $kepsek = sisipkanAkunLama('kepsek@tkta8.test', 'super_admin', 'aktif');
    DB::table('password_reset_tokens')->insert(['email' => 'nur.aini@guru.tkta8.test', 'token' => Hash::make('token'), 'created_at' => now()]);

    jalankanMigrasiAkunGuru();

    expect(DB::table('users')->where('id', $guru)->value('password'))->toBeNull()
        ->and(Hash::check('guru2026', (string) DB::table('users')->where('id', $kepsek)->value('password')))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('menjadikan email staff huruf kecil dan menghapus notifikasi guru_baru', function () {
    $kepsek = sisipkanAkunLama('Kepsek@TKTA8.test', 'super_admin', 'aktif');
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\GuruBaruNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $kepsek,
        'data' => json_encode(['jenis' => 'guru_baru', 'judul' => 'Pendaftaran guru baru', 'pesan' => '-', 'url' => '/mudarris/guru/1']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    jalankanMigrasiAkunGuru();

    expect(DB::table('users')->where('id', $kepsek)->value('email'))->toBe('kepsek@tkta8.test')
        ->and(DB::table('notifications')->count())->toBe(0);
});
