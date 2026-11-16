<?php

namespace App\Services;

use App\Enums\Hubungan;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Models\Murid;
use App\Models\MuridWali;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class MuridService
{
    public const FOLDER_FOTO = 'murid';

    public function __construct(
        private readonly MediaService $media,
        private readonly WaliMuridService $waliMuridService,
    ) {}

    /**
     * Murid baru belum punya NIS dan akun wali; keduanya dibuat saat Kepala Sekolah mengisi NIS
     * (`NisMuridService::isi()`). Dipakai juga oleh import murid dan penerimaan PPDB. Status bawaan aktif; import
     * murid mengirim status lain untuk data alumni.
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, ?UploadedFile $foto): Murid
    {
        $fotoPath = $foto === null ? null : $this->media->simpanGambar($foto, MediaService::DISK_PRIVAT, self::FOLDER_FOTO);

        return Murid::query()->create([
            'status' => StatusMurid::Aktif,
            ...$data,
            'nis' => null,
            'foto_path' => $fotoPath,
        ]);
    }

    /**
     * Status murid diubah lewat `StatusMuridService`, bukan di sini. Tanggal lahir yang berubah ikut mengganti
     * password awal akun wali otomatis yang belum pernah dipakai.
     *
     * @param  array<string, mixed>  $data
     */
    public function perbarui(Murid $murid, array $data, ?UploadedFile $foto, User $pengubah): Murid
    {
        $fotoLama = $murid->foto_path;

        DB::transaction(function () use ($murid, $data, $foto, $pengubah): void {
            $murid->fill($data);

            if ($foto !== null) {
                $murid->foto_path = $this->media->simpanGambar($foto, MediaService::DISK_PRIVAT, self::FOLDER_FOTO);
            }

            $tanggalLahirBerubah = $murid->isDirty('tanggal_lahir');
            $murid->save();

            if ($tanggalLahirBerubah) {
                $this->waliMuridService->sesuaikanPasswordAwal($murid, $pengubah);
            }
        });

        if ($foto !== null) {
            $this->media->hapus($fotoLama, MediaService::DISK_PRIVAT);
        }

        return $murid;
    }

    /**
     * Hapus hanya untuk data yang salah input. Murid yang sudah punya tagihan, rapor, atau berasal dari
     * PPDB disimpan sebagai riwayat; statusnya diubah menjadi pindah/keluar. Akun wali otomatis murid ini yang
     * belum pernah dipakai ikut dinonaktifkan dan username-nya dikosongkan.
     *
     * @throws BusinessRuleException
     */
    public function hapus(Murid $murid, User $penghapus): void
    {
        if ($murid->tagihan()->exists() || $murid->rapor()->exists() || $murid->pendaftaran()->exists()) {
            throw new BusinessRuleException("{$murid->nama_lengkap} sudah punya tagihan, rapor, atau data PPDB sehingga tidak bisa dihapus. Ubah statusnya menjadi pindah atau keluar.");
        }

        DB::transaction(function () use ($murid, $penghapus): void {
            $this->waliMuridService->lepasAkunOtomatisBelumDipakai($murid, $penghapus)?->user->update(['username' => null]);
            $murid->kelasMurid()->delete();
            // NIS, NISN, dan username berindeks unik, sedangkan baris ini hanya di-soft-delete; tanpa dikosongkan,
            // nomor yang salah input tidak bisa dipakai murid lain.
            $murid->update(['nis' => null, 'nisn' => null]);
            $murid->delete();
        });
    }

    /**
     * Kalau wali yang dilepas adalah kontak utama, wali yang paling awal tertaut menggantikannya.
     */
    public function lepasWali(Murid $murid, int $waliMuridId, User $kepalaSekolah): void
    {
        $wali = $murid->waliMurid()->with('user')->findOrFail($waliMuridId);
        /** @var MuridWali $tautan */
        $tautan = $wali->getRelation('pivot');

        DB::transaction(function () use ($murid, $wali, $tautan): void {
            $murid->waliMurid()->detach($wali->id);

            $pengganti = $tautan->is_kontak_utama
                ? $murid->waliMurid()->orderByPivot('created_at')->first()
                : null;

            if ($pengganti !== null) {
                $murid->waliMurid()->updateExistingPivot($pengganti->id, ['is_kontak_utama' => true]);
            }
        });

        activity('wali')->causedBy($kepalaSekolah)->performedOn($murid)->event('dilepas')
            ->withProperties(['wali_murid_id' => $wali->id, 'hubungan' => $tautan->hubungan->value])
            ->log("Melepas tautan {$wali->user->name} dari {$murid->nama_lengkap}");
    }

    /**
     * Mengubah hubungan atau kontak utama satu wali. Setiap murid punya tepat satu kontak utama: menjadikan wali
     * ini kontak utama melepas status itu dari wali lain, sedangkan kontak utama tidak bisa dilepas begitu saja
     * (pilih wali lain sebagai kontak utama).
     *
     * @throws BusinessRuleException
     */
    public function ubahTautanWali(Murid $murid, int $waliMuridId, ?Hubungan $hubungan, ?bool $kontakUtama, User $kepalaSekolah): void
    {
        $wali = DB::transaction(function () use ($murid, $waliMuridId, $hubungan, $kontakUtama) {
            Murid::query()->lockForUpdate()->findOrFail($murid->id);
            $wali = $murid->waliMurid()->with('user')->findOrFail($waliMuridId);
            /** @var MuridWali $tautan */
            $tautan = $wali->getRelation('pivot');

            if ($kontakUtama === false && $tautan->is_kontak_utama) {
                throw new BusinessRuleException("{$wali->user->name} adalah kontak utama {$murid->nama_panggilan}. Jadikan wali lain sebagai kontak utama terlebih dahulu.");
            }
            if ($kontakUtama === true && ! $tautan->is_kontak_utama) {
                $murid->waliMurid()->newPivotStatement()->where('murid_id', $murid->id)->update(['is_kontak_utama' => false]);
            }

            $murid->waliMurid()->updateExistingPivot($wali->id, array_filter([
                'hubungan' => $hubungan,
                'is_kontak_utama' => $kontakUtama,
            ], fn (mixed $nilai): bool => $nilai !== null));

            return $wali;
        });

        activity('wali')->causedBy($kepalaSekolah)->performedOn($murid)->event('diubah')
            ->withProperties(array_filter([
                'wali_murid_id' => $wali->id,
                'hubungan' => $hubungan?->value,
                'is_kontak_utama' => $kontakUtama,
            ], fn (mixed $nilai): bool => $nilai !== null))
            ->log("Mengubah tautan {$wali->user->name} dengan {$murid->nama_lengkap}");
    }
}
