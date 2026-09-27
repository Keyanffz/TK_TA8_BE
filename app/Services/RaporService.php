<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusRapor;
use App\Exceptions\BusinessRuleException;
use App\Models\ElemenPenilaian;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Rapor;
use App\Models\RaporDetail;
use App\Models\User;
use App\Notifications\RaporDiajukanNotification;
use App\Notifications\RaporDitarikNotification;
use App\Notifications\RaporRevisiNotification;
use App\Notifications\RaporTerbitNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Alur rapor (A6, B6.9): draft → diajukan → terbit, atau diajukan → revisi → diajukan lagi. Rapor terbit bisa
 * ditarik Kepala Sekolah kembali ke revisi. Guru pembuat mengisi saat `draft`/`revisi`; Kepala Sekolah boleh
 * memperbaiki isi rapor yang sedang `diajukan` sebelum menerbitkannya. Setiap perpindahan status mengunci baris
 * rapor supaya dua aksi bersamaan tidak melompati alur.
 */
class RaporService
{
    private const FOLDER_FOTO = 'rapor';

    private const RELASI_NOTIFIKASI = ['murid', 'kelas', 'tahunAjaran'];

    public function __construct(private readonly MediaService $media) {}

    /**
     * Rapor dibuat di kelas murid pada tahun ajaran aktif yang diampu pembuatnya, dengan satu baris
     * detail kosong per elemen penilaian aktif.
     *
     * @throws BusinessRuleException
     */
    public function buat(Murid $murid, int $semester, User $pembuat): Rapor
    {
        $kelas = $this->kelasAktif($murid, $pembuat);
        $elemen = ElemenPenilaian::query()->where('is_aktif', true)->orderBy('urutan')->get();

        if ($elemen->isEmpty()) {
            throw new BusinessRuleException('Belum ada elemen penilaian aktif. Minta Kepala Sekolah mengaktifkan elemen penilaian terlebih dahulu.');
        }

        return DB::transaction(function () use ($murid, $semester, $pembuat, $kelas, $elemen): Rapor {
            $sudahAda = Rapor::query()->where('murid_id', $murid->id)->where('tahun_ajaran_id', $kelas->tahun_ajaran_id)
                ->where('semester', $semester)->lockForUpdate()->exists();

            if ($sudahAda) {
                throw new BusinessRuleException("Rapor semester {$semester} untuk {$murid->nama_lengkap} di tahun ajaran ini sudah ada.");
            }

            $rapor = Rapor::query()->create([
                'murid_id' => $murid->id,
                'kelas_id' => $kelas->id,
                'tahun_ajaran_id' => $kelas->tahun_ajaran_id,
                'semester' => $semester,
                'status' => StatusRapor::Draft,
                'dibuat_oleh' => $pembuat->profilGuru()->id,
            ]);
            $rapor->detail()->createMany($elemen->map(fn (ElemenPenilaian $satu): array => ['elemen_penilaian_id' => $satu->id])->all());

            return $rapor;
        });
    }

    /**
     * Guru pembuat mengisi saat `draft`/`revisi`; Kepala Sekolah memperbaiki saat `diajukan` (tercatat di log
     * aktivitas, status tidak berubah).
     *
     * @param  array<string, mixed>  $data  tinggi_badan, berat_badan, catatan_guru
     * @param  array<int, string|null>  $deskripsi  deskripsi per `elemen_penilaian_id`
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function isi(Rapor $rapor, array $data, array $deskripsi, User $pengubah): Rapor
    {
        [$rapor, $olehKepalaSekolah] = DB::transaction(function () use ($rapor, $data, $deskripsi, $pengubah): array {
            $rapor = Rapor::query()->lockForUpdate()->findOrFail($rapor->id);
            $olehKepalaSekolah = $this->pastikanBisaDiisiOleh($rapor, $pengubah);
            $detail = $rapor->detail()->get()->keyBy('elemen_penilaian_id');

            $asing = array_diff(array_keys($deskripsi), $detail->keys()->all());
            if ($asing !== []) {
                throw ValidationException::withMessages(['detail' => 'Elemen penilaian dengan id '.implode(', ', $asing).' tidak ada di rapor ini.']);
            }

            $rapor->update($data);
            foreach ($deskripsi as $elemenId => $isi) {
                $detail[$elemenId]->update(['deskripsi' => $isi === null ? null : trim($isi)]);
            }

            return [$rapor, $olehKepalaSekolah];
        });

        if ($olehKepalaSekolah) {
            $rapor->load('murid');
            activity('rapor')->causedBy($pengubah)->performedOn($rapor)->event('diubah')
                ->log("Memperbaiki isi rapor semester {$rapor->semester} {$rapor->murid->nama_lengkap} sebelum terbit");
        }

        return $rapor;
    }

    /**
     * Foto hasil karya atau kegiatan anak untuk satu elemen; foto lama diganti.
     *
     * @throws BusinessRuleException
     */
    public function simpanFoto(Rapor $rapor, RaporDetail $detail, UploadedFile $foto): RaporDetail
    {
        $path = $this->media->simpanGambar($foto, MediaService::DISK_PRIVAT, self::FOLDER_FOTO);
        $pathLama = $detail->foto_path;

        try {
            DB::transaction(function () use ($rapor, $detail, $path): void {
                $this->kunciYangBisaDiisi($rapor);
                $detail->update(['foto_path' => $path]);
            });
        } catch (Throwable $e) {
            $this->media->hapus($path, MediaService::DISK_PRIVAT);

            throw $e;
        }

        $this->media->hapus($pathLama, MediaService::DISK_PRIVAT);

        return $detail;
    }

    /**
     * Semua elemen harus sudah berdeskripsi sebelum diajukan.
     *
     * @throws BusinessRuleException
     */
    public function ajukan(Rapor $rapor): Rapor
    {
        $rapor = DB::transaction(function () use ($rapor): Rapor {
            $rapor = $this->kunciYangBisaDiisi($rapor);
            $kosong = $rapor->detail()->with('elemenPenilaian')->get()
                ->filter(fn (RaporDetail $detail): bool => blank($detail->deskripsi))
                ->map(fn (RaporDetail $detail): string => $detail->elemenPenilaian->nama);

            if ($kosong->isNotEmpty()) {
                throw new BusinessRuleException('Lengkapi deskripsi elemen berikut sebelum mengajukan rapor: '.$kosong->join(', ').'.');
            }

            $rapor->update(['status' => StatusRapor::Diajukan, 'diajukan_at' => now()]);

            return $rapor;
        });

        $rapor->load([...self::RELASI_NOTIFIKASI, 'pembuat.user']);
        Notification::send(User::query()->kepalaSekolahAktif()->get(), new RaporDiajukanNotification($rapor));

        return $rapor;
    }

    /**
     * @throws BusinessRuleException
     */
    public function terbitkan(Rapor $rapor, User $kepalaSekolah): Rapor
    {
        $rapor = DB::transaction(function () use ($rapor, $kepalaSekolah): Rapor {
            $rapor = $this->kunciYangDiajukan($rapor, 'diterbitkan');
            $rapor->update(['status' => StatusRapor::Terbit, 'terbit_at' => now(), 'disetujui_oleh' => $kepalaSekolah->id]);

            return $rapor;
        });

        $rapor->load([...self::RELASI_NOTIFIKASI, 'murid.waliMurid.user']);
        activity('rapor')->causedBy($kepalaSekolah)->performedOn($rapor)->event('terbit')
            ->log("Menerbitkan rapor semester {$rapor->semester} {$rapor->murid->nama_lengkap}");
        Notification::send($rapor->murid->waliMurid->pluck('user'), new RaporTerbitNotification($rapor));

        return $rapor;
    }

    /**
     * @throws BusinessRuleException
     */
    public function mintaRevisi(Rapor $rapor, string $catatan, User $kepalaSekolah): Rapor
    {
        $rapor = DB::transaction(function () use ($rapor, $catatan): Rapor {
            $rapor = $this->kunciYangDiajukan($rapor, 'dikembalikan untuk revisi');
            $rapor->update(['status' => StatusRapor::Revisi, 'catatan_revisi' => $catatan]);

            return $rapor;
        });

        $rapor->load([...self::RELASI_NOTIFIKASI, 'pembuat.user']);
        activity('rapor')->causedBy($kepalaSekolah)->performedOn($rapor)->event('revisi')
            ->withProperties(['catatan' => $catatan])
            ->log("Meminta revisi rapor semester {$rapor->semester} {$rapor->murid->nama_lengkap}");
        $rapor->pembuat->user->notify(new RaporRevisiNotification($rapor, $catatan));

        return $rapor;
    }

    /**
     * Menarik rapor yang sudah terbit kembali ke `revisi` (misalnya ada kesalahan yang baru ketahuan). Wali
     * tidak lagi bisa melihatnya sampai rapor diterbitkan ulang; guru pembuat diberi tahu beserta catatannya.
     *
     * @throws BusinessRuleException
     */
    public function tarik(Rapor $rapor, string $catatan, User $kepalaSekolah): Rapor
    {
        $rapor = DB::transaction(function () use ($rapor, $catatan): Rapor {
            $rapor = Rapor::query()->lockForUpdate()->findOrFail($rapor->id);

            if ($rapor->status !== StatusRapor::Terbit) {
                throw new BusinessRuleException("Rapor berstatus {$rapor->status->label()} tidak bisa ditarik. Hanya rapor yang sudah terbit yang bisa ditarik.");
            }

            $rapor->update(['status' => StatusRapor::Revisi, 'catatan_revisi' => $catatan, 'terbit_at' => null, 'disetujui_oleh' => null]);

            return $rapor;
        });

        $rapor->load([...self::RELASI_NOTIFIKASI, 'pembuat.user']);
        activity('rapor')->causedBy($kepalaSekolah)->performedOn($rapor)->event('ditarik')
            ->withProperties(['catatan' => $catatan])
            ->log("Menarik rapor semester {$rapor->semester} {$rapor->murid->nama_lengkap} yang sudah terbit untuk direvisi");
        $rapor->pembuat->user->notify(new RaporDitarikNotification($rapor, $catatan));

        return $rapor;
    }

    /**
     * @return bool true kalau diisi Kepala Sekolah sebagai peninjau (bukan sebagai guru pembuat)
     *
     * @throws BusinessRuleException
     */
    private function pastikanBisaDiisiOleh(Rapor $rapor, User $pengubah): bool
    {
        $pembuat = $pengubah->guru?->id === $rapor->dibuat_oleh;
        $kepalaSekolah = $pengubah->role === Role::SuperAdmin;

        if ($pembuat && in_array($rapor->status, [StatusRapor::Draft, StatusRapor::Revisi], true)) {
            return false;
        }
        if ($kepalaSekolah && $rapor->status === StatusRapor::Diajukan) {
            return true;
        }

        throw new BusinessRuleException("Rapor berstatus {$rapor->status->label()} tidak bisa diubah. ".($pembuat
            ? 'Rapor hanya bisa diisi saat berstatus draft atau revisi.'
            : 'Kepala Sekolah hanya bisa memperbaiki rapor yang sedang diajukan.'));
    }

    /**
     * @throws BusinessRuleException
     */
    private function kelasAktif(Murid $murid, User $pembuat): Kelas
    {
        $kelas = $murid->kelasAktif()
            ->wherePivot('status', StatusKelasMurid::Aktif)
            ->diampuOleh($pembuat)
            ->first();

        return $kelas ?? throw new BusinessRuleException("{$murid->nama_lengkap} belum punya kelas aktif di tahun ajaran ini, jadi rapornya belum bisa dibuat.");
    }

    /**
     * @throws BusinessRuleException
     */
    private function kunciYangBisaDiisi(Rapor $rapor): Rapor
    {
        $rapor = Rapor::query()->lockForUpdate()->findOrFail($rapor->id);

        if (! in_array($rapor->status, [StatusRapor::Draft, StatusRapor::Revisi], true)) {
            throw new BusinessRuleException("Rapor berstatus {$rapor->status->label()} tidak bisa diubah. Rapor hanya bisa diisi saat berstatus draft atau revisi.");
        }

        return $rapor;
    }

    /**
     * @throws BusinessRuleException
     */
    private function kunciYangDiajukan(Rapor $rapor, string $aksi): Rapor
    {
        $rapor = Rapor::query()->lockForUpdate()->findOrFail($rapor->id);

        if ($rapor->status !== StatusRapor::Diajukan) {
            throw new BusinessRuleException("Rapor berstatus {$rapor->status->label()} tidak bisa {$aksi}. Hanya rapor yang sudah diajukan guru yang bisa direview.");
        }

        return $rapor;
    }
}
