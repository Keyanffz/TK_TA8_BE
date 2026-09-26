<?php

namespace App\Services;

use App\Models\GaleriAlbum;
use App\Models\GaleriFoto;
use App\Support\SlugUnik;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Album galeri untuk website (disk public, B5). Slug album dibuat sekali dari judul dan tidak berubah supaya
 * tautan yang sudah dibagikan tetap jalan. File ikut dihapus saat foto, sampul, atau album dihapus.
 */
class GaleriService
{
    public const MAKSIMAL_FOTO_PER_UNGGAHAN = 20;

    private const FOLDER = 'galeri';

    public function __construct(private readonly MediaService $media) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, ?UploadedFile $cover): GaleriAlbum
    {
        $coverPath = $cover === null ? null : $this->media->simpanGambar($cover, MediaService::DISK_PUBLIK, self::FOLDER);

        return GaleriAlbum::query()->create([
            'is_publik' => false,
            ...$data,
            'slug' => SlugUnik::dari(GaleriAlbum::query(), (string) $data['judul'], 'album'),
            'cover_path' => $coverPath,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function perbarui(GaleriAlbum $album, array $data, ?UploadedFile $cover): GaleriAlbum
    {
        $coverLama = $album->cover_path;

        if ($cover !== null) {
            $data['cover_path'] = $this->media->simpanGambar($cover, MediaService::DISK_PUBLIK, self::FOLDER);
        }

        $album->update($data);

        if ($cover !== null && ! $this->dipakaiFoto($album, $coverLama)) {
            $this->media->hapus($coverLama, MediaService::DISK_PUBLIK);
        }

        return $album;
    }

    public function hapus(GaleriAlbum $album): void
    {
        $path = array_unique(array_filter([$album->cover_path, ...$album->foto()->pluck('path')->all()]));

        DB::transaction(function () use ($album): void {
            $album->foto()->delete();
            $album->delete();
        });

        foreach ($path as $satu) {
            $this->media->hapus($satu, MediaService::DISK_PUBLIK);
        }
    }

    /**
     * @param  list<UploadedFile>  $foto
     */
    public function tambahFoto(GaleriAlbum $album, array $foto): GaleriAlbum
    {
        $path = [];

        try {
            foreach ($foto as $file) {
                $path[] = $this->media->simpanGambar($file, MediaService::DISK_PUBLIK, self::FOLDER);
            }

            DB::transaction(function () use ($album, $path): void {
                $urutan = (int) $album->foto()->max('urutan');
                foreach ($path as $satu) {
                    $album->foto()->create(['path' => $satu, 'urutan' => ++$urutan]);
                }
            });
        } catch (Throwable $e) {
            foreach ($path as $satu) {
                $this->media->hapus($satu, MediaService::DISK_PUBLIK);
            }

            throw $e;
        }

        return $album;
    }

    /**
     * Foto yang sedang menjadi sampul album dilepas dari sampul lebih dulu.
     */
    public function hapusFoto(GaleriFoto $foto): void
    {
        DB::transaction(function () use ($foto): void {
            GaleriAlbum::query()->whereKey($foto->galeri_album_id)->where('cover_path', $foto->path)->update(['cover_path' => null]);
            $foto->delete();
        });

        $this->media->hapus($foto->path, MediaService::DISK_PUBLIK);
    }

    private function dipakaiFoto(GaleriAlbum $album, ?string $path): bool
    {
        return $path !== null && $album->foto()->where('path', $path)->exists();
    }
}
