<?php

namespace App\Support;

use App\Enums\JenisAbsensi;
use App\Services\PengaturanService;
use Carbon\CarbonInterface;

/**
 * Aturan absensi dari grup pengaturan `absensi` (A4 "Kunci pengaturan"). Jam disimpan sebagai teks `HH:MM`
 * dan dibandingkan per menit dengan jam server, jadi batas "07:15" masih berlaku sampai 07:15:59.
 */
final readonly class AturanAbsensi
{
    public const GRUP = 'absensi';

    /** Hari kerja memakai nomor hari ISO: 1 = Senin sampai 7 = Minggu. */
    public const BAWAAN = [
        'absensi.lokasi' => null,
        'absensi.radius_meter' => 100,
        'absensi.batas_akurasi_meter' => 100,
        'absensi.jam_masuk' => ['buka' => '06:30', 'batas_terlambat' => '07:15', 'tutup' => '09:00'],
        'absensi.jam_pulang' => ['buka' => '11:00', 'tutup' => '15:00'],
        'absensi.hari_kerja' => [1, 2, 3, 4, 5, 6],
        'absensi.tanggal_libur' => [],
        'absensi.masa_simpan_foto_bulan' => 6,
    ];

    /**
     * @param  array{latitude: float, longitude: float}|null  $lokasi
     * @param  array{buka: string, batas_terlambat: string, tutup: string}  $jamMasuk
     * @param  array{buka: string, tutup: string}  $jamPulang
     * @param  list<int>  $hariKerja
     * @param  list<string>  $tanggalLibur
     */
    private function __construct(
        public ?array $lokasi,
        public int $radiusMeter,
        public int $batasAkurasiMeter,
        public array $jamMasuk,
        public array $jamPulang,
        public array $hariKerja,
        public array $tanggalLibur,
        public int $masaSimpanFotoBulan,
    ) {}

    public static function dari(PengaturanService $pengaturan): self
    {
        $nilai = fn (string $nama): mixed => $pengaturan->nilai(self::GRUP.'.'.$nama, self::BAWAAN[self::GRUP.'.'.$nama]);
        $lokasi = $nilai('lokasi');

        return new self(
            lokasi: is_array($lokasi) ? ['latitude' => (float) $lokasi['latitude'], 'longitude' => (float) $lokasi['longitude']] : null,
            radiusMeter: (int) $nilai('radius_meter'),
            batasAkurasiMeter: (int) $nilai('batas_akurasi_meter'),
            jamMasuk: $nilai('jam_masuk'),
            jamPulang: $nilai('jam_pulang'),
            hariKerja: array_map(intval(...), $nilai('hari_kerja')),
            tanggalLibur: $nilai('tanggal_libur'),
            masaSimpanFotoBulan: (int) $nilai('masa_simpan_foto_bulan'),
        );
    }

    public function hariKerja(CarbonInterface $waktu): bool
    {
        return in_array($waktu->dayOfWeekIso, $this->hariKerja, true);
    }

    public function tanggalLibur(CarbonInterface $waktu): bool
    {
        return in_array($waktu->toDateString(), $this->tanggalLibur, true);
    }

    /**
     * @return array{buka: string, tutup: string}
     */
    public function jendela(JenisAbsensi $jenis): array
    {
        $jam = $jenis === JenisAbsensi::Masuk ? $this->jamMasuk : $this->jamPulang;

        return ['buka' => $jam['buka'], 'tutup' => $jam['tutup']];
    }

    public function jendelaTerbuka(JenisAbsensi $jenis, CarbonInterface $waktu): bool
    {
        $jendela = $this->jendela($jenis);
        $jam = $waktu->format('H:i');

        return $jam >= $jendela['buka'] && $jam <= $jendela['tutup'];
    }

    public function terlambat(CarbonInterface $waktu): bool
    {
        return $waktu->format('H:i') > $this->jamMasuk['batas_terlambat'];
    }

    public function jendelaSudahTutup(JenisAbsensi $jenis, CarbonInterface $waktu): bool
    {
        return $waktu->format('H:i') > $this->jendela($jenis)['tutup'];
    }
}
