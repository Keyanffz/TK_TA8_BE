<?php

namespace App\Services;

use App\Enums\JenisAbsensi;
use App\Enums\StatusAbsensi;
use App\Exceptions\BusinessRuleException;
use App\Models\Absensi;
use App\Models\User;
use App\Support\AturanAbsensi;
use App\Support\Jarak;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Absensi guru dan Kepala Sekolah berbasis lokasi dan foto (B6.14). Semua keputusan memakai jam server dan
 * pengaturan grup `absensi`; koordinat dan akurasi dari perangkat hanya dibandingkan, tidak dipercaya untuk waktu.
 */
class AbsensiService
{
    public const FOLDER_FOTO = 'absensi';

    private const UKURAN_CHUNK = 100;

    public function __construct(
        private readonly PengaturanService $pengaturan,
        private readonly MediaService $media,
    ) {}

    public function aturan(): AturanAbsensi
    {
        return AturanAbsensi::dari($this->pengaturan);
    }

    /**
     * Absensi masuk dan pulang milik satu peserta pada satu tanggal, berkunci nilai `JenisAbsensi`.
     *
     * @return Collection<string, Absensi>
     */
    public function absensiTanggal(User $user, Carbon $tanggal): Collection
    {
        return Absensi::query()->with('pengoreksi')
            ->where('user_id', $user->id)
            ->whereDate('tanggal', $tanggal->toDateString())
            ->get()
            ->keyBy(fn (Absensi $absensi): string => $absensi->jenis->value);
    }

    /**
     * Urutan pemeriksaan: lokasi sekolah sudah diatur, hari kerja, tanggal libur, jendela jam, belum absen
     * jenis itu, absen pulang butuh absen masuk, akurasi, lalu jarak. Foto baru disimpan setelah semua lolos.
     *
     * @throws BusinessRuleException
     */
    public function absen(User $user, JenisAbsensi $jenis, float $latitude, float $longitude, float $akurasi, UploadedFile $foto): Absensi
    {
        $sekarang = now();
        $aturan = $this->aturan();
        $lokasi = $aturan->lokasi ?? throw new BusinessRuleException('Lokasi sekolah belum diatur. Minta Kepala Sekolah mengisi pengaturan absensi.');

        $this->pastikanWaktuAbsen($aturan, $jenis, $sekarang);
        $this->pastikanBolehAbsen($user, $jenis, $sekarang);

        $jarak = $this->jarakDalamRadius($aturan, $lokasi, $latitude, $longitude, $akurasi);

        $path = $this->media->simpanGambar($foto, MediaService::DISK_PRIVAT, self::FOLDER_FOTO);

        try {
            return Absensi::query()->create([
                'user_id' => $user->id,
                'tanggal' => $sekarang->toDateString(),
                'jenis' => $jenis,
                'status' => $jenis === JenisAbsensi::Masuk ? ($aturan->terlambat($sekarang) ? StatusAbsensi::Terlambat : StatusAbsensi::Hadir) : null,
                'waktu' => $sekarang,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'akurasi_meter' => (int) ceil($akurasi),
                'jarak_meter' => (int) round($jarak),
                'foto_path' => $path,
            ])->load('pengoreksi');
        } catch (Throwable $e) {
            $this->media->hapus($path, MediaService::DISK_PRIVAT);

            // Dua permintaan bersamaan sama-sama lolos pengecekan; unique (user, tanggal, jenis) menolak yang kedua.
            throw $e instanceof UniqueConstraintViolationException
                ? new BusinessRuleException("Anda sudah absen {$jenis->value} hari ini.")
                : $e;
        }
    }

    /**
     * Koreksi status absen masuk oleh Kepala Sekolah. Pengoreksi dan waktunya disimpan di baris absensi dan
     * di log aktivitas (status sebelum dan sesudah).
     *
     * @throws BusinessRuleException
     */
    public function koreksi(Absensi $absensi, StatusAbsensi $status, string $catatan, User $pelaku): Absensi
    {
        if ($absensi->jenis !== JenisAbsensi::Masuk) {
            throw new BusinessRuleException('Hanya status absen masuk yang bisa dikoreksi. Absen pulang tidak punya status.');
        }
        if ($absensi->status === $status) {
            throw new BusinessRuleException("Status absensi ini sudah {$status->label()}.");
        }

        $sebelum = $absensi->status;
        $absensi->update([
            'status' => $status,
            'catatan_koreksi' => $catatan,
            'dikoreksi_oleh' => $pelaku->id,
            'dikoreksi_at' => now(),
        ]);

        activity('absensi')->performedOn($absensi)->causedBy($pelaku)->event('dikoreksi')
            ->withProperties(['user_id' => $absensi->user_id, 'tanggal' => $absensi->tanggal->toDateString(), 'sebelum' => $sebelum?->value, 'sesudah' => $status->value, 'catatan' => $catatan])
            ->log("Mengoreksi absensi {$absensi->tanggal->toDateString()} menjadi {$status->label()}");

        return $absensi->load('pengoreksi');
    }

    /**
     * Setelah jam masuk tutup di hari kerja, peserta aktif yang belum punya absen masuk hari itu ditandai
     * tidak hadir. Aman dijalankan berulang. Akun yang dibuat setelah jam masuk tutup tidak ditandai.
     *
     * @return int jumlah peserta yang ditandai (atau akan ditandai, saat simulasi)
     */
    public function tandaiTidakHadir(Carbon $waktu, bool $simulasi = false): int
    {
        $aturan = $this->aturan();

        if (! $aturan->hariKerja($waktu) || $aturan->tanggalLibur($waktu) || ! $aturan->jendelaSudahTutup(JenisAbsensi::Masuk, $waktu)) {
            return 0;
        }

        $tanggal = $waktu->toDateString();
        $tutup = $waktu->copy()->setTimeFromTimeString($aturan->jamMasuk['tutup'])->endOfMinute();
        $peserta = User::query()->pesertaAbsensi()
            ->where('created_at', '<=', $tutup)
            ->whereDoesntHave('absensi', fn (Builder $absensi) => $absensi->whereDate('tanggal', $tanggal)->where('jenis', JenisAbsensi::Masuk))
            ->pluck('id');

        if ($simulasi) {
            return $peserta->count();
        }

        foreach ($peserta as $userId) {
            Absensi::query()->firstOrCreate(
                ['user_id' => $userId, 'tanggal' => $tanggal, 'jenis' => JenisAbsensi::Masuk],
                ['status' => StatusAbsensi::TidakHadir],
            );
        }

        return $peserta->count();
    }

    /**
     * Menghapus file foto yang tanggal absensinya sudah melewati `absensi.masa_simpan_foto_bulan`. Baris
     * absensinya tetap ada dengan `foto_path` kosong.
     *
     * @return int jumlah foto yang dihapus (atau akan dihapus, saat simulasi)
     */
    public function hapusFotoLama(Carbon $hariIni, bool $simulasi = false): int
    {
        $batas = $hariIni->copy()->subMonthsNoOverflow($this->aturan()->masaSimpanFotoBulan)->toDateString();
        $query = Absensi::query()->whereNotNull('foto_path')->whereDate('tanggal', '<', $batas);

        if ($simulasi) {
            return $query->count();
        }

        $jumlah = 0;
        $query->chunkById(self::UKURAN_CHUNK, function (Collection $absensi) use (&$jumlah): void {
            foreach ($absensi as $satu) {
                $this->media->hapus($satu->foto_path, MediaService::DISK_PRIVAT);
                $satu->update(['foto_path' => null]);
                $jumlah++;
            }
        });

        return $jumlah;
    }

    /**
     * @throws BusinessRuleException
     */
    private function pastikanWaktuAbsen(AturanAbsensi $aturan, JenisAbsensi $jenis, Carbon $sekarang): void
    {
        if (! $aturan->hariKerja($sekarang)) {
            throw new BusinessRuleException("Hari {$sekarang->translatedFormat('l')} bukan hari kerja, jadi tidak ada absensi.");
        }
        if ($aturan->tanggalLibur($sekarang)) {
            throw new BusinessRuleException('Hari ini libur sekolah, jadi tidak ada absensi.');
        }
        if (! $aturan->jendelaTerbuka($jenis, $sekarang)) {
            $jendela = $aturan->jendela($jenis);

            throw new BusinessRuleException("Absen {$jenis->value} hanya bisa pukul {$jendela['buka']}–{$jendela['tutup']}. Sekarang pukul {$sekarang->format('H:i')}.");
        }
    }

    /**
     * @param  array{latitude: float, longitude: float}  $lokasi
     * @return float jarak ke sekolah dalam meter
     *
     * @throws BusinessRuleException
     */
    private function jarakDalamRadius(AturanAbsensi $aturan, array $lokasi, float $latitude, float $longitude, float $akurasi): float
    {
        if ($akurasi > $aturan->batasAkurasiMeter) {
            throw new BusinessRuleException(sprintf(
                'Akurasi lokasi %d m, melebihi batas %d m. Nyalakan GPS, pindah ke tempat terbuka, lalu coba lagi.',
                (int) ceil($akurasi),
                $aturan->batasAkurasiMeter,
            ));
        }

        $jarak = Jarak::meter($latitude, $longitude, $lokasi['latitude'], $lokasi['longitude']);
        if ($jarak > $aturan->radiusMeter) {
            throw new BusinessRuleException(sprintf(
                'Anda berada %d m dari sekolah, di luar radius absen %d m. Absen dari area sekolah.',
                (int) ceil($jarak),
                $aturan->radiusMeter,
            ));
        }

        return $jarak;
    }

    /**
     * @throws BusinessRuleException
     */
    private function pastikanBolehAbsen(User $user, JenisAbsensi $jenis, Carbon $sekarang): void
    {
        $hariIni = $this->absensiTanggal($user, $sekarang);
        $masuk = $hariIni->get(JenisAbsensi::Masuk->value);
        $sudahMasuk = $masuk !== null && $masuk->status !== StatusAbsensi::TidakHadir;

        if ($jenis === JenisAbsensi::Pulang && ! $sudahMasuk) {
            throw new BusinessRuleException('Absen pulang hanya bisa setelah absen masuk, dan hari ini Anda belum absen masuk.');
        }

        $sudahAda = $hariIni->get($jenis->value);
        if ($sudahAda !== null) {
            throw new BusinessRuleException($sudahAda->waktu === null
                ? "Absen {$jenis->value} hari ini sudah tercatat tidak hadir. Hubungi Kepala Sekolah untuk koreksi."
                : "Anda sudah absen {$jenis->value} hari ini pukul {$sudahAda->waktu->format('H:i')}.");
        }
    }
}
