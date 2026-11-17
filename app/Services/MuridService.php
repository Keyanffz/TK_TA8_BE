<?php

namespace App\Services;

use App\Enums\Hubungan;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Models\Murid;
use App\Models\MuridWali;
use App\Models\OrangTua;
use App\Models\User;
use App\Support\FormulirMurid;
use App\Support\IsianKosong;
use App\Support\KelengkapanFormulir;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MuridService
{
    public const FOLDER_FOTO = 'murid';

    public function __construct(
        private readonly MediaService $media,
        private readonly WaliMuridService $waliMuridService,
        private readonly OrangTuaService $orangTua,
    ) {}

    /**
     * Murid baru belum punya NIS dan akun wali; keduanya dibuat saat Kepala Sekolah mengisi NIS
     * (`NisMuridService::isi()`). Dipakai juga oleh import murid dan penerimaan PPDB. Status bawaan aktif; import
     * murid mengirim status lain untuk data alumni. `ayah`, `ibu`, dan `wali` disimpan ke tabel orang tua.
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, ?UploadedFile $foto): Murid
    {
        $fotoPath = $foto === null ? null : $this->media->simpanGambar($foto, MediaService::DISK_PRIVAT, self::FOLDER_FOTO);

        return DB::transaction(function () use ($data, $fotoPath): Murid {
            $murid = Murid::query()->create([
                'status' => StatusMurid::Aktif,
                ...Arr::except($data, FormulirMurid::PERAN),
                'nis' => null,
                'foto_path' => $fotoPath,
            ]);
            $this->orangTua->simpan($murid, Arr::only($data, FormulirMurid::PERAN));
            $murid->perbaruiKelengkapan();

            return $murid;
        });
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
            $murid->fill(Arr::except($data, FormulirMurid::PERAN));

            if ($foto !== null) {
                $murid->foto_path = $this->media->simpanGambar($foto, MediaService::DISK_PRIVAT, self::FOLDER_FOTO);
            }

            $tanggalLahirBerubah = $murid->isDirty('tanggal_lahir');
            $murid->save();
            $this->orangTua->simpan($murid, Arr::only($data, FormulirMurid::PERAN));
            $murid->perbaruiKelengkapan();

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
     * PPDB disimpan sebagai riwayat; statusnya diubah menjadi pindah/keluar.
     *
     * @throws BusinessRuleException
     */
    public function hapus(Murid $murid, User $penghapus): void
    {
        if ($murid->tagihan()->exists() || $murid->rapor()->exists() || $murid->pendaftaran()->exists()) {
            throw new BusinessRuleException("{$murid->nama_lengkap} sudah punya tagihan, rapor, atau data PPDB sehingga tidak bisa dihapus. Ubah statusnya menjadi pindah atau keluar.");
        }

        $this->hapusData($murid, $penghapus);
    }

    /**
     * Tanpa pemeriksaan riwayat; dipakai juga saat pendaftar PPDB yang sudah diterima mengundurkan diri. Akun wali
     * otomatis murid ini yang belum pernah dipakai ikut dinonaktifkan dan username-nya dikosongkan.
     */
    public function hapusData(Murid $murid, User $penghapus): void
    {
        DB::transaction(function () use ($murid, $penghapus): void {
            $this->waliMuridService->lepasAkunOtomatisBelumDipakai($murid, $penghapus)?->user->update(['username' => null]);
            $murid->kelasMurid()->delete();
            // NIS, NISN, NIK, dan username berindeks unik, sedangkan baris ini hanya di-soft-delete; tanpa dikosongkan,
            // nomor yang salah input tidak bisa dipakai murid lain atau mendaftar PPDB lagi.
            $murid->update(['nis' => null, 'nisn' => null, 'nik' => null]);
            $murid->delete();
        });
    }

    /**
     * Wali melengkapi isian formulir yang masih kosong. Isian yang sudah terisi hanya boleh dikirim dengan nilai yang
     * sama; nilai lain ditolak karena perubahan data yang sudah ada dilakukan lewat sekolah.
     *
     * @param  array<string, mixed>  $data  hasil validasi `LengkapiDataAnakRequest`
     *
     * @throws ValidationException
     */
    public function lengkapi(Murid $murid, array $data, User $pengisi): Murid
    {
        return DB::transaction(function () use ($murid, $data, $pengisi): Murid {
            $murid = Murid::query()->with('orangTua')->lockForUpdate()->findOrFail($murid->id);
            $lengkapi = new IsianKosong(FormulirMurid::atribut());
            $anak = $lengkapi->gabung(
                array_map(FormulirMurid::polos(...), $murid->only(array_keys(Arr::except($data, FormulirMurid::PERAN)))),
                Arr::except($data, FormulirMurid::PERAN),
            );
            $orangTua = $murid->orangTua->mapWithKeys(fn (OrangTua $orang): array => [$orang->peran->value => $orang->isian()])->all();
            $orangTuaBaru = [];

            foreach (FormulirMurid::PERAN as $peran) {
                if (is_array($data[$peran] ?? null)) {
                    $orangTuaBaru[$peran] = $lengkapi->gabungOrangTua($peran, $orangTua[$peran] ?? array_fill_keys(OrangTua::ISIAN, null), $data[$peran]);
                }
            }

            $lengkapi->pastikanTanpaBentrok();
            $kontak = KelengkapanFormulir::masalahKontakUtama(
                [...array_map(FormulirMurid::polos(...), $murid->only(['kontak_utama', 'status_yatim_piatu'])), ...$anak],
                [...$orangTua, ...$orangTuaBaru],
            );

            if ($kontak !== []) {
                throw ValidationException::withMessages($kontak);
            }

            $murid->fill($anak)->save();
            $this->orangTua->simpan($murid, $orangTuaBaru);
            $murid->perbaruiKelengkapan();

            activity('murid')->causedBy($pengisi)->performedOn($murid)->event('dilengkapi_wali')
                ->withProperties(['field' => $lengkapi->diisi()])
                ->log("Wali melengkapi data {$murid->nama_lengkap}");

            return $murid;
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
