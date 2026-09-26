<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Audit N+1 dengan data demo (B2): jumlah query sebuah daftar tidak boleh bertambah saat halaman berisi
 * lebih banyak baris. Pembanding 8 dan 20 baris, bukan 1–3, karena eager load untuk relasi yang semua
 * kuncinya null (misalnya pembayar pada pembayaran tunai) dilewati Laravel dan membuat halaman kecil
 * tampak lebih hemat. Seluruh daftar diperiksa dalam satu test karena DemoSeeder butuh beberapa detik.
 */
function jumlahQuery(object $test, ?User $user, string $url): int
{
    $jumlah = 0;
    DB::listen(function () use (&$jumlah) {
        $jumlah++;
    });

    $user === null ? $test->getJson($url)->assertOk() : $test->actingAs($user)->getJson($url)->assertOk();
    DB::flushQueryLog();

    return $jumlah;
}

it('tidak menambah query saat daftar berisi lebih banyak baris', function () {
    Storage::fake('local');
    Storage::fake('public');
    config(['superadmin.name' => 'Hj. Umi Kulsum, S.Pd.', 'superadmin.email' => 'kepsek@tkta8.test', 'superadmin.password' => 'kepsek2026']);
    $this->seed(DemoSeeder::class);

    $kepsek = User::query()->where('role', Role::SuperAdmin)->firstOrFail();
    foreach (range(1, 4) as $urutan) {
        activity('tagihan')->causedBy($urutan % 2 === 0 ? $kepsek : null)->log("Contoh log {$urutan}");
    }

    $pengguna = [
        'publik' => null,
        'kepsek' => $kepsek,
        'guru' => User::query()->where('email', 'nur.aini@guru.tkta8.test')->firstOrFail(),
        'bendahara' => User::query()->where('email', 'siti.rahmawati@guru.tkta8.test')->firstOrFail(),
        'wali' => User::query()->where('role', Role::WaliMurid)->whereHas('waliMurid', fn ($wali) => $wali->has('murid', '>=', 2))->firstOrFail(),
    ];
    $daftar = [
        'kepsek' => ['/guru', '/wali-murid', '/tahun-ajaran', '/kelas', '/murid', '/tagihan', '/pembayaran', '/jenis-tagihan', '/keringanan',
            '/kegiatan', '/rapor', '/pengumuman', '/pendaftaran', '/galeri-album', '/log-aktivitas'],
        'guru' => ['/murid', '/tagihan', '/kegiatan', '/rapor', '/pengumuman', '/kelas'],
        'bendahara' => ['/pembayaran', '/tagihan'],
        'wali' => ['/murid', '/tagihan', '/pembayaran', '/kegiatan', '/rapor', '/pengumuman', '/pendaftaran'],
        'publik' => ['/public/pengumuman', '/public/galeri'],
    ];

    $bertambah = [];
    foreach ($daftar as $role => $paths) {
        foreach ($paths as $path) {
            $url = "/api/v1{$path}?per_page=";
            jumlahQuery($this, $pengguna[$role], $url.'1');
            $sedikit = jumlahQuery($this, $pengguna[$role], $url.'8');
            $banyak = jumlahQuery($this, $pengguna[$role], $url.'20');

            if ($banyak !== $sedikit) {
                $bertambah[] = "{$role} {$path}: {$sedikit} query untuk 8 baris, {$banyak} untuk 20 baris";
            }
        }
    }

    expect($bertambah)->toBe([]);
});
