<?php

namespace App\Services;

use App\Enums\Hubungan;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Models\MuridWali;
use App\Models\User;
use App\Support\NomorUrut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MuridService
{
    public const FOLDER_FOTO = 'murid';

    private const DIGIT_URUT_NIS = 4;

    public function __construct(
        private readonly MediaService $media,
        private readonly KelasService $kelasService,
        private readonly WaliMuridService $waliMuridService,
    ) {}

    /**
     * NIS dibuat otomatis dengan format `TA{tahun masuk}{urut 4 digit}`; nomor urut mulai lagi tiap tahun. Murid
     * baru langsung dibuatkan akun wali dengan username NIS-nya (hubungan `wali`, bisa diubah Kepala Sekolah).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function buat(array $data, ?UploadedFile $foto, User $kepalaSekolah): Murid
    {
        $fotoPath = $foto === null ? null : $this->media->simpanGambar($foto, MediaService::DISK_PRIVAT, self::FOLDER_FOTO);

        return DB::transaction(function () use ($data, $fotoPath, $kepalaSekolah): Murid {
            $murid = $this->buatDenganFotoTersimpan($data, $fotoPath);
            $this->waliMuridService->buatAkunOtomatis($murid, Hubungan::Wali, null, $kepalaSekolah);

            return $murid;
        });
    }

    /**
     * Sama dengan `buat()`, untuk foto yang sudah ada di disk private (misalnya salinan pas foto PPDB).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function buatDenganFotoTersimpan(array $data, ?string $fotoPath): Murid
    {
        return DB::transaction(fn (): Murid => Murid::query()->create([
            ...$data,
            'nis' => $this->nisBaru(Carbon::parse($data['tanggal_masuk'])),
            'status' => StatusMurid::Aktif,
            'foto_path' => $fotoPath,
        ]));
    }

    /**
     * Perubahan status ikut mengubah penempatan murid di tahun ajaran aktif: lulus → `lulus`,
     * pindah/keluar → `keluar`, kembali aktif → penempatan dibuka lagi (dengan cek kapasitas).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function perbarui(Murid $murid, array $data, ?UploadedFile $foto): Murid
    {
        $fotoLama = $murid->foto_path;
        $statusLama = $murid->status;
        $statusBaru = StatusMurid::from($data['status']);

        DB::transaction(function () use ($murid, $data, $foto, $statusLama, $statusBaru): void {
            $murid->fill([...$data, 'tanggal_keluar' => $statusBaru === StatusMurid::Aktif ? null : $data['tanggal_keluar']]);

            if ($foto !== null) {
                $murid->foto_path = $this->media->simpanGambar($foto, MediaService::DISK_PRIVAT, self::FOLDER_FOTO);
            }

            $murid->save();

            if ($statusLama !== $statusBaru) {
                $this->sesuaikanPenempatan($murid, $statusBaru);
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
     * belum pernah dipakai ikut dinonaktifkan.
     *
     * @throws BusinessRuleException
     */
    public function hapus(Murid $murid, User $kepalaSekolah): void
    {
        if ($murid->tagihan()->exists() || $murid->rapor()->exists() || $murid->pendaftaran()->exists()) {
            throw new BusinessRuleException("{$murid->nama_lengkap} sudah punya tagihan, rapor, atau data PPDB sehingga tidak bisa dihapus. Ubah statusnya menjadi pindah atau keluar.");
        }

        DB::transaction(function () use ($murid, $kepalaSekolah): void {
            $this->waliMuridService->lepasAkunOtomatisBelumDipakai($murid, $kepalaSekolah);
            $murid->kelasMurid()->delete();
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

    /**
     * Murid yang di-soft delete ikut dihitung karena kolom `nis` unik.
     *
     * @throws BusinessRuleException
     */
    private function nisBaru(Carbon $tanggalMasuk): string
    {
        $awalan = 'TA'.$tanggalMasuk->year;

        return NomorUrut::format($awalan, NomorUrut::berikutnya(Murid::withTrashed(), 'nis', $awalan, self::DIGIT_URUT_NIS), self::DIGIT_URUT_NIS);
    }

    /**
     * @throws BusinessRuleException
     */
    private function sesuaikanPenempatan(Murid $murid, StatusMurid $statusBaru): void
    {
        [$dari, $ke] = match ($statusBaru) {
            StatusMurid::Aktif => [[StatusKelasMurid::Lulus, StatusKelasMurid::Keluar], StatusKelasMurid::Aktif],
            StatusMurid::Lulus => [[StatusKelasMurid::Aktif], StatusKelasMurid::Lulus],
            StatusMurid::Pindah, StatusMurid::Keluar => [[StatusKelasMurid::Aktif], StatusKelasMurid::Keluar],
        };

        $penempatan = KelasMurid::query()
            ->with('kelas')
            ->where('murid_id', $murid->id)
            ->whereIn('status', $dari)
            ->whereHas('kelas.tahunAjaran', fn (Builder $tahunAjaran) => $tahunAjaran->where('is_aktif', true))
            ->lockForUpdate()
            ->first();

        if ($penempatan === null) {
            return;
        }

        if ($ke === StatusKelasMurid::Aktif) {
            $this->kelasService->pastikanMuatKapasitas($penempatan->kelas, 1);
        }

        $penempatan->update(['status' => $ke]);
    }
}
