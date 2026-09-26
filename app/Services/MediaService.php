<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penyimpanan file dan URL-nya (B5). File private (foto anak, bukti bayar, dokumen PPDB) disajikan
 * lewat signed URL `GET /media/{token}` yang berisi path terenkripsi, bukan path mentah.
 */
class MediaService
{
    public const DISK_PRIVAT = 'local';

    public const DISK_PUBLIK = 'public';

    public const MENIT_BERLAKU_URL_PRIVAT = 30;

    /** Batas per file B5: 5 MB. */
    public const UKURAN_MAKSIMAL_KB = 5 * 1024;

    public const EKSTENSI_GAMBAR = ['jpg', 'jpeg', 'png', 'webp'];

    /** Dokumen PPDB boleh berupa gambar atau PDF (B5). */
    public const EKSTENSI_DOKUMEN = [...self::EKSTENSI_GAMBAR, 'pdf'];

    private const LEBAR_MAKSIMAL_GAMBAR = 1600;

    private const KUALITAS_JPEG = 80;

    /**
     * Aturan validasi unggahan gambar (B5), dipakai semua FormRequest yang menerima gambar.
     *
     * @return list<string>
     */
    public static function aturanGambar(): array
    {
        return ['image', 'mimes:'.implode(',', self::EKSTENSI_GAMBAR), 'max:'.self::UKURAN_MAKSIMAL_KB];
    }

    /**
     * @return list<string>
     */
    public static function aturanDokumen(): array
    {
        return ['file', 'mimes:'.implode(',', self::EKSTENSI_DOKUMEN), 'max:'.self::UKURAN_MAKSIMAL_KB];
    }

    /**
     * Gambar diproses seperti `simpanGambar()`; PDF disimpan apa adanya dengan nama acak.
     */
    public function simpanDokumen(UploadedFile $file, string $disk, string $folder): string
    {
        if ($file->getMimeType() !== 'application/pdf') {
            return $this->simpanGambar($file, $disk, $folder);
        }

        return (string) Storage::disk($disk)->putFileAs($folder, $file, Str::uuid().'.pdf');
    }

    /**
     * Menyalin file private ke folder lain dengan nama acak baru, misalnya pas foto PPDB menjadi foto murid.
     */
    public function salinPrivat(string $path, string $folder): string
    {
        $tujuan = $folder.'/'.Str::uuid().'.'.pathinfo($path, PATHINFO_EXTENSION);
        Storage::disk(self::DISK_PRIVAT)->copy($path, $tujuan);

        return $tujuan;
    }

    /**
     * Gambar diperkecil ke lebar maksimal 1600 px, diputar sesuai EXIF, disimpan sebagai JPEG kualitas 80
     * dengan nama acak. Metadata EXIF (termasuk lokasi GPS) dibuang karena sebagian besar foto berisi anak.
     */
    public function simpanGambar(UploadedFile $file, string $disk, string $folder): string
    {
        $gambar = ImageManager::usingDriver(Driver::class, strip: true)
            ->decodePath((string) $file->getRealPath())
            ->scaleDown(width: self::LEBAR_MAKSIMAL_GAMBAR)
            ->encode(new JpegEncoder(self::KUALITAS_JPEG));

        $path = $folder.'/'.Str::uuid().'.jpg';
        Storage::disk($disk)->put($path, (string) $gambar);

        return $path;
    }

    public function hapus(?string $path, string $disk): void
    {
        if ($path !== null) {
            Storage::disk($disk)->delete($path);
        }
    }

    public function urlPublik(?string $path): ?string
    {
        return $path === null ? null : Storage::disk(self::DISK_PUBLIK)->url($path);
    }

    /**
     * Signature dihitung dari path relatif supaya URL tetap valid di balik proxy/HTTPS terminator
     * yang mengubah skema atau host.
     */
    public function urlPrivat(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $token = strtr(rtrim(Crypt::encryptString($path), '='), '+/', '-_');

        return url(URL::temporarySignedRoute(
            'media',
            now()->addMinutes(self::MENIT_BERLAKU_URL_PRIVAT),
            ['token' => $token],
            absolute: false,
        ));
    }

    public function respons(string $token): ?StreamedResponse
    {
        try {
            $path = Crypt::decryptString(strtr($token, '-_', '+/'));
        } catch (DecryptException) {
            return null;
        }

        return $this->responsPrivat($path);
    }

    /**
     * Menyajikan file private langsung, untuk endpoint yang memeriksa hak akses sendiri
     * (misalnya `GET /pembayaran/{id}/bukti`). Null kalau file tidak ada.
     */
    public function responsPrivat(string $path): ?StreamedResponse
    {
        if (! Storage::disk(self::DISK_PRIVAT)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK_PRIVAT)->response($path, null, [
            'Cache-Control' => 'private, max-age='.(self::MENIT_BERLAKU_URL_PRIVAT * 60),
        ]);
    }
}
