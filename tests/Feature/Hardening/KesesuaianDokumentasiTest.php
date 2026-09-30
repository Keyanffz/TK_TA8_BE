<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Respons JSON sungguhan setiap endpoint GET (dengan data demo, per role) harus cocok dengan skema di
 * dokumentasi OpenAPI yang dipakai FE untuk membuat tipe TypeScript.
 */
it('mencocokkan respons GET setiap role dengan dokumentasi OpenAPI', function () {
    Storage::fake('local');
    Storage::fake('public');
    config(['superadmin.name' => 'Hj. Umi Kulsum, S.Pd.', 'superadmin.email' => 'kepsek@tkta8.test', 'superadmin.password' => 'kepsek2026']);
    $this->seed(DemoSeeder::class);
    activity('tagihan')->causedBy(User::query()->where('role', Role::SuperAdmin)->first())->log('Contoh log');
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();

    $pengguna = [
        'publik' => null,
        'kepsek' => User::query()->where('role', Role::SuperAdmin)->firstOrFail(),
        'guru' => User::query()->where('email', 'nur.aini@guru.tkta8.test')->firstOrFail(),
        'bendahara' => User::query()->where('email', 'siti.rahmawati@guru.tkta8.test')->firstOrFail(),
        'wali' => User::query()->where('role', Role::WaliMurid)->whereHas('waliMurid', fn ($wali) => $wali->has('murid', '>=', 2))->firstOrFail(),
    ];
    $pengguna['kepsek']->notify(notifikasiPendaftarBaru(1, 'Fitri Handayani'));

    $ambil = function (?User $user, string $url) {
        // Sesi actingAs tetap menempel sampai guard dilupakan, jadi request publik harus membersihkannya dulu.
        app('auth')->forgetGuards();

        return $user === null ? $this->getJson('/api/v1'.$url) : $this->actingAs($user)->getJson('/api/v1'.$url);
    };

    $selisih = [];
    $diperiksa = [];
    $catatan = [];
    foreach ($dokumen['paths'] as $path => $operasi) {
        $skema = isset($operasi['get']) ? skemaSukses($dokumen, $path) : [];
        if ($skema === [] || str_contains($path, '{token}')) {
            continue;
        }

        foreach ($pengguna as $role => $user) {
            $url = $path;
            if (str_contains($path, '{')) {
                $daftar = $ambil($user, (string) preg_replace('#/\{[a-z_]+\}.*$#', '', $path));
                $pertama = $daftar->status() === 200 ? ($daftar->json('data.0') ?? null) : null;
                if (! is_array($pertama)) {
                    continue;
                }
                $url = preg_replace(['#\{slug\}#', '#\{[a-z_]+\}#'], [(string) ($pertama['slug'] ?? ''), (string) $pertama['id']], $path, 1);
            }

            $respons = $ambil($user, $url);
            if ($respons->status() !== 200) {
                continue;
            }

            $diperiksa[] = "{$role} {$url}";
            $selisih = [...$selisih, ...selisihDenganSkema($respons->json(), $skema, $dokumen, "{$role} {$url}")];
            catatFieldSelaluAda($respons->json(), $skema, $dokumen, "GET {$path}", $catatan);
        }
    }

    // Field yang muncul di setiap respons sungguhan (semua role, semua item) harus wajib di skema, supaya tipe
    // TypeScript di FE tidak menandainya opsional.
    $opsional = [];
    foreach ($catatan as $lokasi => ['wajib' => $wajib, 'selalu' => $selalu]) {
        foreach (array_diff($selalu, $wajib) as $field) {
            $opsional[] = "{$lokasi}.{$field}";
        }
    }

    expect($selisih)->toBe([])
        ->and($opsional)->toBe([])
        ->and(count($diperiksa))->toBeGreaterThan(100);
});
