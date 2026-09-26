<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\KegiatanFoto;
use App\Models\KegiatanKelas;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Kegiatan kelas dan fotonya. Foto berisi anak, jadi disimpan di disk private (B5). File disimpan sebelum
 * transaksi dan dihapus lagi kalau penyimpanan data gagal; file milik data yang dihapus ikut dihapus.
 */
class KegiatanKelasService
{
    public const MAKSIMAL_FOTO_PER_UNGGAHAN = 10;

    /** Batas total supaya halaman detail kegiatan tetap ringan dibuka di HP. */
    public const MAKSIMAL_FOTO_PER_KEGIATAN = 30;

    private const FOLDER_FOTO = 'kegiatan';

    public function __construct(private readonly MediaService $media) {}

    /**
     * Pembuat kegiatan adalah profil guru pengguna; untuk Kepala Sekolah, profil guru miliknya (A2.1).
     *
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $foto
     */
    public function buat(array $data, array $foto, User $pembuat): KegiatanKelas
    {
        $path = $this->simpanFoto($foto);

        try {
            return DB::transaction(function () use ($data, $path, $pembuat): KegiatanKelas {
                $kegiatan = KegiatanKelas::query()->create([...$data, 'guru_id' => $pembuat->profilGuru()->id]);
                $this->lampirkanFoto($kegiatan, $path, 1);

                return $kegiatan;
            });
        } catch (Throwable $e) {
            $this->hapusFile($path);

            throw $e;
        }
    }

    /**
     * @param  list<UploadedFile>  $foto
     *
     * @throws BusinessRuleException
     */
    public function tambahFoto(KegiatanKelas $kegiatan, array $foto): KegiatanKelas
    {
        $path = $this->simpanFoto($foto);

        try {
            DB::transaction(function () use ($kegiatan, $path): void {
                $kegiatan = KegiatanKelas::query()->lockForUpdate()->findOrFail($kegiatan->id);
                $jumlah = $kegiatan->foto()->count();

                if ($jumlah + count($path) > self::MAKSIMAL_FOTO_PER_KEGIATAN) {
                    $sisa = self::MAKSIMAL_FOTO_PER_KEGIATAN - $jumlah;

                    throw new BusinessRuleException('Satu kegiatan paling banyak berisi '.self::MAKSIMAL_FOTO_PER_KEGIATAN." foto. Kegiatan ini masih bisa ditambah {$sisa} foto.");
                }

                $this->lampirkanFoto($kegiatan, $path, (int) $kegiatan->foto()->max('urutan') + 1);
            });
        } catch (Throwable $e) {
            $this->hapusFile($path);

            throw $e;
        }

        return $kegiatan;
    }

    public function hapus(KegiatanKelas $kegiatan): void
    {
        $path = $kegiatan->foto()->pluck('path')->all();

        DB::transaction(function () use ($kegiatan): void {
            $kegiatan->foto()->delete();
            $kegiatan->delete();
        });

        $this->hapusFile($path);
    }

    public function hapusFoto(KegiatanFoto $foto): void
    {
        $foto->delete();
        $this->media->hapus($foto->path, MediaService::DISK_PRIVAT);
    }

    /**
     * @param  list<UploadedFile>  $foto
     * @return list<string>
     */
    private function simpanFoto(array $foto): array
    {
        $path = [];

        try {
            foreach ($foto as $file) {
                $path[] = $this->media->simpanGambar($file, MediaService::DISK_PRIVAT, self::FOLDER_FOTO);
            }
        } catch (Throwable $e) {
            $this->hapusFile($path);

            throw $e;
        }

        return $path;
    }

    /**
     * @param  list<string>  $path
     */
    private function lampirkanFoto(KegiatanKelas $kegiatan, array $path, int $urutanAwal): void
    {
        foreach ($path as $i => $satu) {
            $kegiatan->foto()->create(['path' => $satu, 'urutan' => $urutanAwal + $i]);
        }
    }

    /**
     * @param  list<string>  $path
     */
    private function hapusFile(array $path): void
    {
        foreach ($path as $satu) {
            $this->media->hapus($satu, MediaService::DISK_PRIVAT);
        }
    }
}
