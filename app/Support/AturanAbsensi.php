<?php

namespace App\Support;

use App\Enums\JenisAbsensi;
use App\Services\PengaturanService;
use Carbon\CarbonInterface;

/**
 * Aturan absensi dari grup pengaturan `absensi` (A4 "Kunci pengaturan"). Jam disimpan sebagai teks `HH:MM`
 * dan dibandingkan per menit dengan jam server, jadi batas "07:15" masih berlaku sampai 07:15:59.
 *
 * Jam masuk dan jam pulang suatu tanggal dipilih berurutan: jam per rentang tanggal (`absensi.jam_tanggal`, misalnya
 * Ramadan), lalu jam khusus harinya (`absensi.jam_khusus`), lalu jam bawaan. Semua aturan yang memakai jam meminta
 * jam lewat `jamMasuk()` dan `jamPulang()` dengan tanggalnya, jadi pemilihan jam per tanggal hanya ada di kelas ini.
 */
final readonly class AturanAbsensi
{
    public const GRUP = 'absensi';

    /** Tidak ada di `BAWAAN` karena nilai awalnya tanggal migration dijalankan, bukan nilai tetap. */
    public const KUNCI_TANGGAL_MULAI = 'absensi.tanggal_mulai';

    /** Hari kerja dan hari di jam khusus memakai nomor hari ISO: 1 = Senin sampai 7 = Minggu. */
    public const BAWAAN = [
        'absensi.lokasi' => null,
        'absensi.radius_meter' => 100,
        'absensi.batas_akurasi_meter' => 100,
        'absensi.jam_masuk' => ['buka' => '06:30', 'batas_terlambat' => '07:15', 'tutup' => '09:00'],
        'absensi.jam_pulang' => ['buka' => '11:00', 'tutup' => '15:00'],
        'absensi.jam_khusus' => [],
        'absensi.jam_tanggal' => [],
        'absensi.hari_kerja' => [1, 2, 3, 4, 5, 6],
        'absensi.tanggal_libur' => [],
        'absensi.masa_simpan_foto_bulan' => 6,
    ];

    /**
     * @param  array{latitude: float, longitude: float}|null  $lokasi
     * @param  array{buka: string, batas_terlambat: string, tutup: string}  $jamMasukBawaan
     * @param  array{buka: string, tutup: string}  $jamPulangBawaan
     * @param  array<int, array{hari: int, jam_masuk: array{buka: string, batas_terlambat: string, tutup: string}, jam_pulang: array{buka: string, tutup: string}}>  $jamKhusus  berkunci nomor hari ISO
     * @param  list<array{mulai: string, selesai: string, keterangan: string, jam_masuk: array{buka: string, batas_terlambat: string, tutup: string}, jam_pulang: array{buka: string, tutup: string}}>  $jamTanggal  rentang tidak bertumpuk
     * @param  list<int>  $hariKerja
     * @param  list<string>  $tanggalLibur
     */
    private function __construct(
        public ?array $lokasi,
        public int $radiusMeter,
        public int $batasAkurasiMeter,
        private array $jamMasukBawaan,
        private array $jamPulangBawaan,
        private array $jamKhusus,
        private array $jamTanggal,
        public array $hariKerja,
        public array $tanggalLibur,
        public int $masaSimpanFotoBulan,
        public ?string $tanggalMulai,
    ) {}

    public static function dari(PengaturanService $pengaturan): self
    {
        $nilai = fn (string $nama): mixed => $pengaturan->nilai(self::GRUP.'.'.$nama, self::BAWAAN[self::GRUP.'.'.$nama]);
        $lokasi = $nilai('lokasi');
        $mulai = $pengaturan->nilai(self::KUNCI_TANGGAL_MULAI);

        return new self(
            lokasi: is_array($lokasi) ? ['latitude' => (float) $lokasi['latitude'], 'longitude' => (float) $lokasi['longitude']] : null,
            radiusMeter: (int) $nilai('radius_meter'),
            batasAkurasiMeter: (int) $nilai('batas_akurasi_meter'),
            jamMasukBawaan: $nilai('jam_masuk'),
            jamPulangBawaan: $nilai('jam_pulang'),
            jamKhusus: array_column($nilai('jam_khusus'), null, 'hari'),
            jamTanggal: $nilai('jam_tanggal'),
            hariKerja: array_map(intval(...), $nilai('hari_kerja')),
            tanggalLibur: $nilai('tanggal_libur'),
            masaSimpanFotoBulan: (int) $nilai('masa_simpan_foto_bulan'),
            tanggalMulai: is_string($mulai) ? $mulai : null,
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
     * Sebelum `absensi.tanggal_mulai` tidak ada absensi: absen ditolak dan scheduler tidak menandai tidak hadir.
     */
    public function sudahMulai(CarbonInterface $waktu): bool
    {
        return $this->tanggalMulai === null || $waktu->toDateString() >= $this->tanggalMulai;
    }

    /**
     * @return array{buka: string, batas_terlambat: string, tutup: string}
     */
    public function jamMasuk(CarbonInterface $tanggal): array
    {
        return $this->rentangPada($tanggal)['jam_masuk'] ?? $this->jamKhusus[$tanggal->dayOfWeekIso]['jam_masuk'] ?? $this->jamMasukBawaan;
    }

    /**
     * @return array{buka: string, tutup: string}
     */
    public function jamPulang(CarbonInterface $tanggal): array
    {
        return $this->rentangPada($tanggal)['jam_pulang'] ?? $this->jamKhusus[$tanggal->dayOfWeekIso]['jam_pulang'] ?? $this->jamPulangBawaan;
    }

    /**
     * @return array{mulai: string, selesai: string, keterangan: string, jam_masuk: array{buka: string, batas_terlambat: string, tutup: string}, jam_pulang: array{buka: string, tutup: string}}|null
     */
    private function rentangPada(CarbonInterface $tanggal): ?array
    {
        $hari = $tanggal->toDateString();

        foreach ($this->jamTanggal as $rentang) {
            if ($hari >= $rentang['mulai'] && $hari <= $rentang['selesai']) {
                return $rentang;
            }
        }

        return null;
    }

    /**
     * @return array{buka: string, tutup: string}
     */
    public function jendela(JenisAbsensi $jenis, CarbonInterface $tanggal): array
    {
        $jam = $jenis === JenisAbsensi::Masuk ? $this->jamMasuk($tanggal) : $this->jamPulang($tanggal);

        return ['buka' => $jam['buka'], 'tutup' => $jam['tutup']];
    }

    public function jendelaTerbuka(JenisAbsensi $jenis, CarbonInterface $waktu): bool
    {
        $jendela = $this->jendela($jenis, $waktu);
        $jam = $waktu->format('H:i');

        return $jam >= $jendela['buka'] && $jam <= $jendela['tutup'];
    }

    public function belumBuka(JenisAbsensi $jenis, CarbonInterface $waktu): bool
    {
        return $waktu->format('H:i') < $this->jendela($jenis, $waktu)['buka'];
    }

    public function terlambat(CarbonInterface $waktu): bool
    {
        return $waktu->format('H:i') > $this->jamMasuk($waktu)['batas_terlambat'];
    }

    public function jendelaSudahTutup(JenisAbsensi $jenis, CarbonInterface $waktu): bool
    {
        return $waktu->format('H:i') > $this->jendela($jenis, $waktu)['tutup'];
    }
}
