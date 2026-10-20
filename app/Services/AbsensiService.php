<?php

namespace App\Services;

use App\Enums\JenisAbsensi;
use App\Enums\Role;
use App\Enums\StatusAbsensi;
use App\Exceptions\BusinessRuleException;
use App\Models\Absensi;
use App\Models\User;
use App\Notifications\AbsensiDikoreksiNotification;
use App\Notifications\AbsensiTercatatNotification;
use App\Support\AturanAbsensi;
use App\Support\Jarak;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Absensi guru dan Kepala Sekolah berbasis lokasi dan foto (B6.14). Semua keputusan memakai jam server dan
 * pengaturan grup `absensi`; koordinat dan akurasi dari perangkat hanya dibandingkan, tidak dipercaya untuk waktu.
 */
class AbsensiService
{
    public const FOLDER_FOTO = 'absensi';

    /** WebP lebih kecil dari JPEG berkualitas sama, dan foto absensi bertambah setiap hari kerja. */
    private const FORMAT_FOTO = 'webp';

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
     * Urutan pemeriksaan: lokasi sekolah sudah diatur, tanggal mulai, hari kerja, tanggal libur, jendela jam, absen
     * pulang butuh absen masuk hadir atau terlambat, belum absen jenis itu, akurasi, lalu jarak. Izin dan sakit
     * melewati pemeriksaan lokasi. Absen pulang sebelum jam pulang buka diterima sebagai pulang lebih awal kalau
     * ada keterangan. Foto baru disimpan setelah semua lolos.
     *
     * @param  StatusAbsensi|null  $berhalangan  izin atau sakit (hanya absen masuk); null untuk absen biasa
     * @param  array{latitude: float, longitude: float, akurasi: float}|null  $posisi  null untuk izin dan sakit
     *
     * @throws BusinessRuleException
     */
    public function absen(User $user, JenisAbsensi $jenis, ?StatusAbsensi $berhalangan, ?array $posisi, ?string $keterangan, UploadedFile $foto): Absensi
    {
        $sekarang = now();
        $aturan = $this->aturan();
        $lokasi = $posisi === null ? null : ($aturan->lokasi ?? throw new BusinessRuleException('Lokasi sekolah belum diatur. Minta Kepala Sekolah mengisi pengaturan absensi.'));

        $pulangAwal = $this->pastikanWaktuAbsen($aturan, $jenis, $sekarang, $keterangan);
        $this->pastikanBolehAbsen($user, $jenis, $sekarang);

        $jarak = $posisi !== null && $lokasi !== null ? $this->jarakDalamRadius($aturan, $lokasi, $posisi) : null;

        $absensi = $this->simpanDenganFoto($jenis, [
            'user_id' => $user->id,
            'tanggal' => $sekarang->toDateString(),
            'jenis' => $jenis,
            'status' => match (true) {
                $jenis === JenisAbsensi::Pulang => null,
                $berhalangan !== null => $berhalangan,
                $aturan->terlambat($sekarang) => StatusAbsensi::Terlambat,
                default => StatusAbsensi::Hadir,
            },
            'waktu' => $sekarang,
            'latitude' => $posisi['latitude'] ?? null,
            'longitude' => $posisi['longitude'] ?? null,
            'akurasi_meter' => $posisi === null ? null : (int) ceil($posisi['akurasi']),
            'jarak_meter' => $jarak === null ? null : (int) round($jarak),
            'keterangan' => $keterangan,
            'pulang_awal' => $pulangAwal,
        ], $foto);
        $user->notify(new AbsensiTercatatNotification($absensi));

        return $absensi;
    }

    /**
     * Koreksi status absen masuk oleh Kepala Sekolah atau pemegang izin kelola laporan absensi. Pengoreksi dan
     * waktunya disimpan di baris absensi dan di log aktivitas (status sebelum dan sesudah).
     *
     * @throws BusinessRuleException
     */
    public function koreksi(Absensi $absensi, StatusAbsensi $status, string $catatan, User $pelaku): Absensi
    {
        $this->pastikanBukanAbsensiSendiri($absensi->user_id, $pelaku);

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
     * Absen masuk atau pulang yang dicatat Kepala Sekolah (atau pemegang izin kelola laporan absensi) untuk tanggal
     * yang belum punya baris jenis itu, misalnya guru lupa absen padahal masuk dan scheduler belum menandainya tidak
     * hadir, atau lupa absen pulang. Absen
     * pulang butuh absen masuk hadir atau terlambat di tanggal itu. Barisnya tanpa waktu, lokasi, dan foto, dan
     * langsung bertanda koreksi: scheduler melewati peserta yang sudah punya absen masuk, dan
     * `scopeTidakHadirOtomatis` tidak mencakupnya saat tanggal libur ditambah.
     *
     * @throws BusinessRuleException
     */
    public function koreksiBaru(int $userId, Carbon $tanggal, JenisAbsensi $jenis, ?StatusAbsensi $status, string $catatan, User $pelaku): Absensi
    {
        $this->pastikanBukanAbsensiSendiri($userId, $pelaku);
        $peserta = User::query()->pesertaAbsensi()->find($userId)
            ?? throw new BusinessRuleException('Akun ini bukan peserta absensi. Absensi hanya untuk guru aktif dan Kepala Sekolah.');
        $this->pastikanTanggalAbsensi($tanggal);

        $teksTanggal = $tanggal->translatedFormat('j F Y');
        $sudahAda = $jenis === JenisAbsensi::Masuk
            ? "Absen masuk {$peserta->name} tanggal {$teksTanggal} sudah tercatat. Koreksi status absensi yang sudah ada."
            : "Absen pulang {$peserta->name} tanggal {$teksTanggal} sudah tercatat.";
        $this->pastikanBelumTercatat($peserta, $tanggal, $jenis, $sudahAda);

        try {
            return DB::transaction(function () use ($peserta, $tanggal, $jenis, $status, $catatan, $pelaku): Absensi {
                $absensi = Absensi::query()->create([
                    'user_id' => $peserta->id,
                    'tanggal' => $tanggal->toDateString(),
                    'jenis' => $jenis,
                    'status' => $jenis === JenisAbsensi::Masuk ? $status : null,
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

    /**
     * Foto disimpan dulu, lalu barisnya. Kalau baris gagal disimpan, foto dihapus lagi supaya tidak ada file tanpa
     * absensi.
     *
     * @param  array<string, mixed>  $isi
     *
     * @throws BusinessRuleException
     */
    private function simpanDenganFoto(JenisAbsensi $jenis, array $isi, UploadedFile $foto): Absensi
    {
        $path = $this->media->simpanGambar($foto, MediaService::DISK_PRIVAT, self::FOLDER_FOTO, self::FORMAT_FOTO);

        try {
            return Absensi::query()->create([...$isi, 'foto_path' => $path])->load('pengoreksi');
        } catch (Throwable $e) {
            $this->media->hapus($path, MediaService::DISK_PRIVAT);

            // Dua permintaan bersamaan sama-sama lolos pengecekan; unique (user, tanggal, jenis) menolak yang kedua.
            throw $e instanceof UniqueConstraintViolationException
                ? new BusinessRuleException("Anda sudah absen {$jenis->value} hari ini.")
                : $e;
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function pastikanBelumTercatat(User $peserta, Carbon $tanggal, JenisAbsensi $jenis, string $sudahAda): void
    {
        $tercatat = $this->absensiTanggal($peserta, $tanggal);
        $masuk = $tercatat->get(JenisAbsensi::Masuk->value);
        $absenMasuk = "Absen masuk {$peserta->name} tanggal {$tanggal->translatedFormat('j F Y')}";

        if ($jenis === JenisAbsensi::Pulang && $masuk === null) {
            throw new BusinessRuleException("{$absenMasuk} belum tercatat. Catat absen masuknya dulu sebelum absen pulang.");
        }
        if ($jenis === JenisAbsensi::Pulang && $masuk->status?->berhalangan()) {
            throw new BusinessRuleException("{$absenMasuk} tercatat ".Str::lower($masuk->namaAbsen()).', jadi tidak ada absen pulang.');
        }
        if ($jenis === JenisAbsensi::Pulang && $masuk->status === StatusAbsensi::TidakHadir) {
            throw new BusinessRuleException("{$absenMasuk} tercatat tidak hadir. Koreksi absen masuknya menjadi hadir atau terlambat dulu sebelum absen pulang.");
        }
        if ($tercatat->has($jenis->value)) {
            throw new BusinessRuleException($sudahAda);
        }
    }

    /**
     * Guru yang diberi izin kelola laporan absensi tidak boleh mengoreksi atau mencatat absensinya sendiri; itu tetap
     * dilakukan Kepala Sekolah.
     *
     * @throws BusinessRuleException
     */
    private function pastikanBukanAbsensiSendiri(int $pesertaId, User $pelaku): void
    {
        if ($pesertaId === $pelaku->id && $pelaku->role !== Role::SuperAdmin) {
            throw new BusinessRuleException('Absensi Anda sendiri hanya bisa dikoreksi atau dicatat Kepala Sekolah.');
        }
    }

    /**
     * Log aktivitas untuk setiap koreksi, dan notifikasi ke peserta kecuali Kepala Sekolah mengubah absensinya
     * sendiri. Baris yang baru dibuat (`POST /absensi/koreksi`) diberi tahu sebagai "dicatat", bukan "dikoreksi".
     */
    private function catatKoreksi(Absensi $absensi, ?StatusAbsensi $sebelum, User $pelaku): void
    {
        $tanggal = $absensi->tanggal->toDateString();

        activity('absensi')->performedOn($absensi)->causedBy($pelaku)->event('dikoreksi')
            ->withProperties([
                'user_id' => $absensi->user_id, 'tanggal' => $tanggal, 'jenis' => $absensi->jenis->value,
                'sebelum' => $sebelum?->value, 'sesudah' => $absensi->status?->value, 'catatan' => $absensi->catatan_koreksi,
            ])
            ->log($absensi->jenis === JenisAbsensi::Pulang
                ? "Mencatat absen pulang {$tanggal}"
                : "Mengoreksi absensi {$tanggal} menjadi {$absensi->status?->label()}");

        if ($absensi->user_id !== $pelaku->id) {
            $absensi->loadMissing('user')->user->notify(new AbsensiDikoreksiNotification($absensi, $absensi->wasRecentlyCreated, $pelaku));
        }
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
     * @return bool true untuk absen pulang sebelum jam pulang buka (pulang lebih awal, tanpa persetujuan)
     *
     * @throws BusinessRuleException
     */
    private function pastikanWaktuAbsen(AturanAbsensi $aturan, JenisAbsensi $jenis, Carbon $sekarang, ?string $keterangan): bool
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
        if ($jenis === JenisAbsensi::Pulang && $aturan->belumBuka($jenis, $sekarang)) {
            if ($keterangan === null) {
                throw new BusinessRuleException("Absen pulang baru bisa mulai pukul {$aturan->jamPulang['buka']}. Kalau perlu pulang lebih awal, tulis alasannya di keterangan.");
            }

            return true;
        }
        if (! $aturan->jendelaTerbuka($jenis, $sekarang)) {
            $jendela = $aturan->jendela($jenis);

            throw new BusinessRuleException("Absen {$jenis->value} hanya bisa pukul {$jendela['buka']}–{$jendela['tutup']}. Sekarang pukul {$sekarang->format('H:i')}.");
        }

        return false;
    }

    /**
     * @param  array{latitude: float, longitude: float}  $lokasi
     * @param  array{latitude: float, longitude: float, akurasi: float}  $posisi
     * @return float jarak ke sekolah dalam meter
     *
     * @throws BusinessRuleException
     */
    private function jarakDalamRadius(AturanAbsensi $aturan, array $lokasi, array $posisi): float
    {
        if ($posisi['akurasi'] > $aturan->batasAkurasiMeter) {
            throw new BusinessRuleException(sprintf(
                'Akurasi lokasi %d m, melebihi batas %d m. Nyalakan GPS, pindah ke tempat terbuka, lalu coba lagi.',
                (int) ceil($posisi['akurasi']),
                $aturan->batasAkurasiMeter,
            ));
        }

        $jarak = Jarak::meter($posisi['latitude'], $posisi['longitude'], $lokasi['latitude'], $lokasi['longitude']);
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

        if ($jenis === JenisAbsensi::Pulang && $masuk?->status?->berhalangan()) {
            throw new BusinessRuleException('Hari ini Anda tercatat '.Str::lower($masuk->namaAbsen()).', jadi tidak perlu absen pulang.');
        }
        if ($jenis === JenisAbsensi::Pulang && $masuk?->status === StatusAbsensi::TidakHadir) {
            throw new BusinessRuleException('Anda tercatat tidak hadir hari ini. Hubungi Kepala Sekolah kalau perlu koreksi.');
        }
        if ($jenis === JenisAbsensi::Pulang && $masuk?->status?->perluAbsenPulang() !== true) {
            throw new BusinessRuleException('Absen pulang hanya bisa setelah absen masuk, dan hari ini Anda belum absen masuk.');
        }

        $sudahAda = $hariIni->get($jenis->value);
        if ($sudahAda !== null) {
            throw new BusinessRuleException(match (true) {
                $sudahAda->waktu !== null && $sudahAda->status?->berhalangan() => 'Anda sudah mencatat '.Str::lower($sudahAda->namaAbsen())." hari ini pukul {$sudahAda->waktu->format('H:i')}.",
                $sudahAda->waktu !== null => "Anda sudah absen {$jenis->value} hari ini pukul {$sudahAda->waktu->format('H:i')}.",
                $sudahAda->status === StatusAbsensi::TidakHadir => "Absen {$jenis->value} hari ini sudah tercatat tidak hadir. Hubungi Kepala Sekolah untuk koreksi.",
                $sudahAda->status !== null => "Absen {$jenis->value} hari ini sudah dicatat Kepala Sekolah dengan status {$sudahAda->status->label()}.",
                default => "Absen {$jenis->value} hari ini sudah dicatat Kepala Sekolah.",
            });
        }
    }
}
