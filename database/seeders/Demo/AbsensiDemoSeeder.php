<?php

namespace Database\Seeders\Demo;

use App\Enums\JenisAbsensi;
use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\Pengaturan;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\MediaService;
use App\Services\PengaturanService;
use App\Support\AturanAbsensi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Lokasi sekolah fiktif di Semarang dan absensi guru aktif serta Kepala Sekolah untuk hari kerja dalam 14 hari
 * terakhir (tanpa hari ini, supaya absen bisa langsung dicoba). Foto contoh hanya untuk tiga hari terakhir.
 */
class AbsensiDemoSeeder extends Seeder
{
    private const LOKASI = ['latitude' => -6.9903, 'longitude' => 110.4229];

    private const HARI_KE_BELAKANG = 14;

    private const HARI_BERFOTO = 3;

    public function run(PengaturanService $pengaturan): void
    {
        Pengaturan::query()->updateOrCreate(['kunci' => 'absensi.lokasi'], ['nilai' => self::LOKASI, 'grup' => AturanAbsensi::GRUP]);
        $aturan = AturanAbsensi::dari($pengaturan);
        $peserta = User::query()->pesertaAbsensi()->orderBy('id')->get();

        for ($mundur = self::HARI_KE_BELAKANG; $mundur >= 1; $mundur--) {
            $hari = today()->subDays($mundur);
            if (! $aturan->hariKerja($hari) || $aturan->tanggalLibur($hari)) {
                continue;
            }

            foreach ($peserta as $urutan => $user) {
                $this->buatSatuHari($user, $hari, ($urutan + $mundur) % 7, $mundur <= self::HARI_BERFOTO);
            }
        }
    }

    /**
     * Pola 0–4 hadir, 5 terlambat dan lupa absen pulang, 6 tidak hadir.
     */
    private function buatSatuHari(User $user, Carbon $hari, int $pola, bool $berfoto): void
    {
        if ($pola === 6) {
            Absensi::query()->create(['user_id' => $user->id, 'tanggal' => $hari->toDateString(), 'jenis' => JenisAbsensi::Masuk, 'status' => StatusAbsensi::TidakHadir]);

            return;
        }

        $terlambat = $pola === 5;
        $this->buat($user, $hari->copy()->setTime(7, $terlambat ? 32 : 2 + $pola), JenisAbsensi::Masuk, $terlambat ? StatusAbsensi::Terlambat : StatusAbsensi::Hadir, $berfoto);

        if (! $terlambat) {
            $this->buat($user, $hari->copy()->setTime(12, 10 + $pola), JenisAbsensi::Pulang, null, $berfoto);
        }
    }

    private function buat(User $user, Carbon $waktu, JenisAbsensi $jenis, ?StatusAbsensi $status, bool $berfoto): void
    {
        Absensi::query()->create([
            'user_id' => $user->id,
            'tanggal' => $waktu->toDateString(),
            'jenis' => $jenis,
            'status' => $status,
            'waktu' => $waktu,
            'latitude' => self::LOKASI['latitude'] + fake()->randomFloat(5, -0.0004, 0.0004),
            'longitude' => self::LOKASI['longitude'] + fake()->randomFloat(5, -0.0004, 0.0004),
            'akurasi_meter' => fake()->numberBetween(6, 40),
            'jarak_meter' => fake()->numberBetween(5, 60),
            'foto_path' => $berfoto ? GambarContoh::simpan(MediaService::DISK_PRIVAT, AbsensiService::FOLDER_FOTO, 480, 640) : null,
        ]);
    }
}
