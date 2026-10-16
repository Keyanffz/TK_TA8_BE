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
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Absensi guru dan Kepala Sekolah berbasis lokasi dan foto (B6.14). Semua keputusan memakai jam server dan
 * pengaturan grup `absensi`; koordinat dan akurasi dari perangkat hanya dibandingkan, tidak dipercaya untuk waktu.
 */
class AbsensiService
{
    public const FOLDER_FOTO = 'absensi';

    private const UKURAN_CHUNK = 100;

    /** Batas susulan `tandaiTidakHadir()`: hari kerja yang terlewat lebih lama dari ini tidak disentuh. */
    public const HARI_SUSULAN = 7;

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
     * Urutan pemeriksaan: lokasi sekolah sudah diatur, tanggal mulai, hari kerja, tanggal libur, jendela jam, belum absen
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
        $this->catatKoreksi($absensi, $sebelum, $pelaku);

        return $absensi->load('pengoreksi');
    }

    /**
     * Absen masuk yang dicatat Kepala Sekolah untuk hari yang belum punya baris, misalnya guru lupa absen padahal
     * masuk dan scheduler belum menandainya tidak hadir. Barisnya tanpa waktu, lokasi, dan foto, dan langsung
     * bertanda koreksi: scheduler melewatinya karena sudah ada absen masuk, dan `scopeTidakHadirOtomatis` tidak
     * mencakupnya saat tanggal libur ditambah.
     *
     * @throws BusinessRuleException
     */
    public function koreksiBaru(int $userId, Carbon $tanggal, StatusAbsensi $status, string $catatan, User $pelaku): Absensi
    {
        $peserta = User::query()->pesertaAbsensi()->find($userId)
            ?? throw new BusinessRuleException('Akun ini bukan peserta absensi. Absensi hanya untuk guru aktif dan Kepala Sekolah.');
        $this->pastikanTanggalAbsensi($tanggal);

        $sudahAda = "Absen masuk {$peserta->name} tanggal {$tanggal->translatedFormat('j F Y')} sudah tercatat. Koreksi status absensi yang sudah ada.";
        if ($peserta->absensi()->masuk()->whereDate('tanggal', $tanggal->toDateString())->exists()) {
            throw new BusinessRuleException($sudahAda);
        }

        try {
            return DB::transaction(function () use ($peserta, $tanggal, $status, $catatan, $pelaku): Absensi {
                $absensi = Absensi::query()->create([
                    'user_id' => $peserta->id,
                    'tanggal' => $tanggal->toDateString(),
                    'jenis' => JenisAbsensi::Masuk,
                    'status' => $status,
                    'catatan_koreksi' => $catatan,
                    'dikoreksi_oleh' => $pelaku->id,
                    'dikoreksi_at' => now(),
                ]);
                $this->catatKoreksi($absensi, null, $pelaku);

                return $absensi->load('pengoreksi');
            });
        } catch (UniqueConstraintViolationException) {
            // Scheduler atau absen peserta itu sendiri sempat membuat baris di antara pengecekan dan penyimpanan.
            throw new BusinessRuleException($sudahAda);
        }
    }

    /**
     * Menandai tidak hadir peserta aktif yang belum punya absen masuk, untuk hari ini dan susulan sampai
     * `HARI_SUSULAN` hari ke belakang (misalnya scheduler sempat mati). Hanya hari kerja yang bukan tanggal libur
     * dan jam masuknya sudah tutup; hari ini baru dihitung setelah jam tutup lewat. Aman dijalankan berulang:
     * peserta yang sudah punya baris absen masuk dilewati, dan unique (user, tanggal, jenis) menjaga sisanya.
     * Tanggal sebelum `absensi.tanggal_mulai` tidak pernah ditandai.
     *
     * Hari kerja, tanggal libur, dan jam tutup dibaca dari pengaturan saat command berjalan, begitu juga status
     * akun: riwayat keduanya tidak disimpan. Akun yang dibuat setelah jam masuk tutup hari itu tidak ditandai.
     *
     * @return int jumlah absensi tidak hadir yang dibuat (atau akan dibuat, saat simulasi)
     */
    public function tandaiTidakHadir(Carbon $waktu, bool $simulasi = false): int
    {
        $aturan = $this->aturan();
        $jumlah = 0;

        for ($mundur = self::HARI_SUSULAN; $mundur >= 0; $mundur--) {
            $hari = $waktu->copy()->subDays($mundur);
            $masihBerjalan = $mundur === 0 && ! $aturan->jendelaSudahTutup(JenisAbsensi::Masuk, $waktu);

            if ($masihBerjalan || ! $aturan->sudahMulai($hari) || ! $aturan->hariKerja($hari) || $aturan->tanggalLibur($hari)) {
                continue;
            }

            $jumlah += $this->tandaiTidakHadirPadaHari($hari, $aturan, $simulasi);
        }

        return $jumlah;
    }

    private function tandaiTidakHadirPadaHari(Carbon $hari, AturanAbsensi $aturan, bool $simulasi): int
    {
        $tanggal = $hari->toDateString();
        $tutup = $hari->copy()->setTimeFromTimeString($aturan->jamMasuk['tutup'])->endOfMinute();
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

    private function catatKoreksi(Absensi $absensi, ?StatusAbsensi $sebelum, User $pelaku): void
    {
        $tanggal = $absensi->tanggal->toDateString();

        activity('absensi')->performedOn($absensi)->causedBy($pelaku)->event('dikoreksi')
            ->withProperties(['user_id' => $absensi->user_id, 'tanggal' => $tanggal, 'sebelum' => $sebelum?->value, 'sesudah' => $absensi->status?->value, 'catatan' => $absensi->catatan_koreksi])
            ->log("Mengoreksi absensi {$tanggal} menjadi {$absensi->status?->label()}");
    }

    /**
     * Tanggal yang boleh punya absensi: sudah lewat atau hari ini, tidak sebelum tanggal mulai, hari kerja, dan bukan
     * tanggal libur. Jam tidak diperiksa.
     *
     * @throws BusinessRuleException
     */
    private function pastikanTanggalAbsensi(Carbon $tanggal): void
    {
        $aturan = $this->aturan();
        $teks = $tanggal->translatedFormat('j F Y');

        if ($tanggal->toDateString() > today()->toDateString()) {
            throw new BusinessRuleException("Tanggal {$teks} belum tiba. Absensi hanya bisa dicatat untuk hari ini atau sebelumnya.");
        }
        if (! $aturan->sudahMulai($tanggal)) {
            $mulai = Carbon::parse((string) $aturan->tanggalMulai)->translatedFormat('j F Y');

            throw new BusinessRuleException("Absensi baru berlaku mulai {$mulai}.");
        }
        if (! $aturan->hariKerja($tanggal)) {
            throw new BusinessRuleException("{$tanggal->translatedFormat('l, j F Y')} bukan hari kerja, jadi tidak ada absensi.");
        }
        if ($aturan->tanggalLibur($tanggal)) {
            throw new BusinessRuleException("{$teks} termasuk tanggal libur, jadi tidak ada absensi.");
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function pastikanWaktuAbsen(AturanAbsensi $aturan, JenisAbsensi $jenis, Carbon $sekarang): void
    {
        if (! $aturan->sudahMulai($sekarang)) {
            $mulai = Carbon::parse((string) $aturan->tanggalMulai)->translatedFormat('j F Y');

            throw new BusinessRuleException("Absensi baru berlaku mulai {$mulai}.");
        }
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
            throw new BusinessRuleException(match (true) {
                $sudahAda->waktu !== null => "Anda sudah absen {$jenis->value} hari ini pukul {$sudahAda->waktu->format('H:i')}.",
                $sudahAda->status === StatusAbsensi::TidakHadir => "Absen {$jenis->value} hari ini sudah tercatat tidak hadir. Hubungi Kepala Sekolah untuk koreksi.",
                default => "Absen {$jenis->value} hari ini sudah dicatat Kepala Sekolah dengan status {$sudahAda->status?->label()}.",
            });
        }
    }
}
