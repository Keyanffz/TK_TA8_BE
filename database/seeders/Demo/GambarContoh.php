<?php

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * Gambar polos berwarna sebagai pengganti foto asli di data demo.
 */
final class GambarContoh
{
    private const WARNA = ['#c8e6c9', '#bbdefb', '#ffe0b2', '#f8bbd0', '#d1c4e9', '#fff9c4'];

    private const KUALITAS_JPEG = 80;

    public static function simpan(string $disk, string $folder, int $lebar = 1200, int $tinggi = 900): string
    {
        $gambar = ImageManager::usingDriver(Driver::class)
            ->createImage($lebar, $tinggi)
            ->fill(fake()->randomElement(self::WARNA))
            ->encode(new JpegEncoder(self::KUALITAS_JPEG));

        $path = $folder.'/'.Str::uuid().'.jpg';
        Storage::disk($disk)->put($path, (string) $gambar);

        return $path;
    }
}
