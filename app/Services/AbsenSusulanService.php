<?php

namespace App\Services;

use App\Enums\JenisAbsensi;
use App\Enums\StatusAbsensi;
use App\Exceptions\BusinessRuleException;
use App\Models\Absensi;
use App\Models\AksesAbsenSusulan;
use App\Models\User;
use App\Notifications\AbsensiSusulanDiberikanNotification;
use App\Notifications\AbsensiTercatatNotification;
use App\Support\AturanAbsensi;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Akses absen susulan (A2.12): Kepala Sekolah mengizinkan satu peserta absen sendiri untuk hari ini atau hari kerja
 * terakhir sebelumnya, misalnya karena wifi sekolah mati. Peserta absen dengan swafoto tanpa pemeriksaan lokasi dan
 * jam. Akses tidak menahan scheduler: peserta tetap ditandai tidak hadir sampai absen susulannya dikirim, lalu baris
 * tidak hadir itu diganti.
 */
class AbsenSusulanService
{
    /**
     * Batas pencarian hari kerja terakhir ke belakang. Libur panjang (misalnya Lebaran) bisa lebih dari seminggu,
     * jadi dipakai sebulan; lewat dari itu akses susulan tidak masuk akal lagi.
     */
    private const BATAS_MUNDUR_HARI = 31;

    public function __construct(
        private readonly AbsensiService $absensi,
        private readonly MediaService $media,
    ) {}

    /**
     * @throws BusinessRuleException
     */
    public function beri(int $userId, Carbon $tanggal, JenisAbsensi $jenis, string $catatan, User $pelaku): AksesAbsenSusulan
    {
        if ($userId === $pelaku->id) {
            throw new BusinessRuleException('Kepala Sekolah tidak memberi akses susulan untuk dirinya sendiri. Catat absensi Anda di Rekap Absensi.');
        }

        $peserta = User::query()->pesertaAbsensi()->find($userId)
            ?? throw new BusinessRuleException('Akun ini bukan peserta absensi. Absensi hanya untuk guru aktif dan Kepala Sekolah.');
        $this->absensi->pastikanTanggalAbsensi($tanggal);
        $this->pastikanTanggalSusulan($tanggal);
        $this->pastikanBelumAbsen($peserta, $tanggal, $jenis);

        $sudahDiberi = AksesAbsenSusulan::query()->aktif()
            ->where('user_id', $peserta->id)->whereDate('tanggal', $tanggal->toDateString())->where('jenis', $jenis)
            ->exists();
        if ($sudahDiberi) {
            throw new BusinessRuleException("{$peserta->name} masih punya akses absen {$jenis->value} susulan untuk tanggal {$tanggal->translatedFormat('j F Y')} yang belum dipakai.");
        }

        $akses = DB::transaction(function () use ($peserta, $tanggal, $jenis, $catatan, $pelaku): AksesAbsenSusulan {
            $akses = AksesAbsenSusulan::query()->create([
                'user_id' => $peserta->id,
                'tanggal' => $tanggal->toDateString(),
                'jenis' => $jenis,
                'catatan' => $catatan,
                'diberikan_oleh' => $pelaku->id,
                'berlaku_sampai' => today()->endOfDay(),
            ]);
            $this->catatLog($akses, $pelaku, 'susulan_diberikan', "Memberi akses absen {$jenis->value} susulan {$akses->tanggal->toDateString()}");

            return $akses;
        });
        $peserta->notify(new AbsensiSusulanDiberikanNotification($akses));

        return $akses->load('pemberi');
    }

    /**
     * Mencabut akses yang belum dipakai, termasuk yang sudah kedaluwarsa.
     *
     * @throws BusinessRuleException
     */
    public function cabut(AksesAbsenSusulan $akses, User $pelaku): void
    {
        if ($akses->dipakai_at !== null) {
            throw new BusinessRuleException('Akses absen susulan ini sudah dipakai, jadi tidak bisa dicabut. Koreksi absensinya di Rekap Absensi.');
        }

        DB::transaction(function () use ($akses, $pelaku): void {
            $this->catatLog($akses, $pelaku, 'susulan_dicabut', "Mencabut akses absen {$akses->jenis->value} susulan {$akses->tanggal->toDateString()}");
            $akses->delete();
        });
    }

    /**
     * Absen susulan oleh pemilik akses: status masuk selalu `hadir`, `waktu` jam server saat dikirim, tanpa koordinat.
     * Baris tidak hadir buatan scheduler pada tanggal itu diganti. Foto disimpan dulu dan dihapus lagi kalau
     * penyimpanan gagal.
     *
     * @throws BusinessRuleException
     */
    public function absen(AksesAbsenSusulan $akses, User $user, UploadedFile $foto): Absensi
    {
        $this->pastikanBisaDipakai($akses);
        $this->absensi->pastikanTanggalAbsensi($akses->tanggal);
        $this->pastikanBisaAbsen($user, $akses);

        $path = $this->media->simpanGambar($foto, MediaService::DISK_PRIVAT, AbsensiService::FOLDER_FOTO);

        try {
            $absensi = DB::transaction(function () use ($akses, $user, $path): Absensi {
                $terkunci = AksesAbsenSusulan::query()->lockForUpdate()->findOrFail($akses->id);
                $this->pastikanBisaDipakai($terkunci);

                $isi = [
                    'status' => $akses->jenis === JenisAbsensi::Masuk ? StatusAbsensi::Hadir : null,
                    'waktu' => now(),
                    'foto_path' => $path,
                    'susulan' => true,
                ];
                $tidakHadir = $akses->jenis === JenisAbsensi::Masuk
                    ? Absensi::query()->tidakHadirOtomatis()->where('user_id', $user->id)->whereDate('tanggal', $akses->tanggal->toDateString())->lockForUpdate()->first()
                    : null;
                $absensi = $tidakHadir === null
                    ? Absensi::query()->create(['user_id' => $user->id, 'tanggal' => $akses->tanggal->toDateString(), 'jenis' => $akses->jenis, ...$isi])
                    : tap($tidakHadir)->update($isi);
                $terkunci->update(['dipakai_at' => now()]);

                return $absensi->load('pengoreksi');
            });
        } catch (Throwable $e) {
            $this->media->hapus($path, MediaService::DISK_PRIVAT);

            // Peserta sempat dicatat Kepala Sekolah di antara pengecekan dan penyimpanan.
            throw $e instanceof UniqueConstraintViolationException
                ? new BusinessRuleException("Absen {$akses->jenis->value} tanggal {$akses->tanggal->translatedFormat('j F Y')} sudah tercatat.")
                : $e;
        }

        $user->notify(new AbsensiTercatatNotification($absensi));

        return $absensi;
    }

    /**
     * @throws BusinessRuleException
     */
    private function pastikanBisaDipakai(AksesAbsenSusulan $akses): void
    {
        if ($akses->dipakai_at !== null) {
            throw new BusinessRuleException("Akses absen susulan ini sudah dipakai pukul {$akses->dipakai_at->format('H:i')}.");
        }
        if ($akses->berlaku_sampai->isPast()) {
            throw new BusinessRuleException("Akses absen susulan ini sudah kedaluwarsa sejak {$akses->berlaku_sampai->translatedFormat('j F Y')} pukul {$akses->berlaku_sampai->format('H:i')}. Minta akses baru ke Kepala Sekolah.");
        }
    }

    /**
     * Apakah akses susulan bisa diberikan untuk tanggal ini (tanpa melihat absensi peserta): hari absensi yang sah,
     * dan hari ini atau hari kerja terakhir sebelumnya. Dipakai FE untuk menampilkan tombol di absensi harian.
     */
    public function tanggalBisaDiberikan(CarbonInterface $tanggal): bool
    {
        $aturan = $this->absensi->aturan();

        $terakhir = $this->hariKerjaTerakhir($aturan);

        return ! $tanggal->isFuture() && $aturan->sudahMulai($tanggal) && $aturan->hariKerja($tanggal) && ! $aturan->tanggalLibur($tanggal)
            && ($tanggal->isToday() || ($terakhir !== null && $tanggal->isSameDay($terakhir)));
    }

    /**
     * Hari ini, atau hari kerja terakhir sebelum hari ini yang bukan tanggal libur.
     *
     * @throws BusinessRuleException
     */
    private function pastikanTanggalSusulan(Carbon $tanggal): void
    {
        if ($tanggal->isToday()) {
            return;
        }

        $terakhir = $this->hariKerjaTerakhir($this->absensi->aturan());
        if ($terakhir === null || ! $tanggal->isSameDay($terakhir)) {
            $pilihan = $terakhir === null ? 'hari ini' : "hari ini atau {$terakhir->translatedFormat('l, j F Y')}";

            throw new BusinessRuleException("Akses absen susulan hanya untuk hari ini atau hari kerja terakhir sebelumnya. Pilih {$pilihan}.");
        }
    }

    private function hariKerjaTerakhir(AturanAbsensi $aturan): ?Carbon
    {
        $hari = today()->subDay();

        for ($mundur = 0; $mundur < self::BATAS_MUNDUR_HARI && $aturan->sudahMulai($hari); $mundur++, $hari->subDay()) {
            if ($aturan->hariKerja($hari) && ! $aturan->tanggalLibur($hari)) {
                return $hari;
            }
        }

        return null;
    }

    /**
     * Akses hanya untuk absensi yang belum ada; baris tidak hadir buatan scheduler yang belum dikoreksi boleh diganti.
     *
     * @throws BusinessRuleException
     */
    private function pastikanBelumAbsen(User $peserta, Carbon $tanggal, JenisAbsensi $jenis): void
    {
        $tercatat = $this->absensi->absensiTanggal($peserta, $tanggal);
        $sudahAda = $tercatat->get($jenis->value);
        $masuk = $tercatat->get(JenisAbsensi::Masuk->value);
        $absen = "Absen {$jenis->value} {$peserta->name} tanggal {$tanggal->translatedFormat('j F Y')}";

        if ($jenis === JenisAbsensi::Pulang && $masuk?->status?->berhalangan()) {
            throw new BusinessRuleException("{$peserta->name} tercatat ".Str::lower($masuk->namaAbsen())." tanggal {$tanggal->translatedFormat('j F Y')}, jadi tidak ada absen pulang.");
        }
        if ($sudahAda !== null && ! $this->tidakHadirOtomatis($sudahAda)) {
            throw new BusinessRuleException("{$absen} sudah tercatat. Koreksi absensinya di Rekap Absensi.");
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function pastikanBisaAbsen(User $user, AksesAbsenSusulan $akses): void
    {
        $tercatat = $this->absensi->absensiTanggal($user, $akses->tanggal);
        $sudahAda = $tercatat->get($akses->jenis->value);
        $masuk = $tercatat->get(JenisAbsensi::Masuk->value);
        $tanggal = $akses->tanggal->translatedFormat('j F Y');

        if ($akses->jenis === JenisAbsensi::Pulang && $masuk?->status?->perluAbsenPulang() !== true) {
            throw new BusinessRuleException("Absen pulang susulan hanya bisa setelah absen masuk tanggal {$tanggal} tercatat hadir atau terlambat.");
        }
        if ($sudahAda !== null && ! $this->tidakHadirOtomatis($sudahAda)) {
            throw new BusinessRuleException("Absen {$akses->jenis->value} tanggal {$tanggal} sudah tercatat.");
        }
    }

    private function tidakHadirOtomatis(Absensi $absensi): bool
    {
        return Absensi::query()->tidakHadirOtomatis()->whereKey($absensi->id)->exists();
    }

    private function catatLog(AksesAbsenSusulan $akses, User $pelaku, string $event, string $pesan): void
    {
        activity('absensi')->performedOn($akses)->causedBy($pelaku)->event($event)
            ->withProperties([
                'user_id' => $akses->user_id, 'tanggal' => $akses->tanggal->toDateString(),
                'jenis' => $akses->jenis->value, 'catatan' => $akses->catatan,
            ])
            ->log($pesan);
    }
}
