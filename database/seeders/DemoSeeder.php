<?php

namespace Database\Seeders;

use App\Models\TahunAjaran;
use Database\Seeders\Demo\AbsensiDemoSeeder;
use Database\Seeders\Demo\AkademikDemoSeeder;
use Database\Seeders\Demo\KeuanganDemoSeeder;
use Database\Seeders\Demo\KomunikasiDemoSeeder;
use Database\Seeders\Demo\PpdbDemoSeeder;
use Database\Seeders\Demo\SekolahDemoSeeder;
use Database\Seeders\Demo\WebsiteDemoSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Data contoh untuk pengembangan lokal (B8). Jalankan di database kosong:
 * `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder`.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder berisi data contoh dan tidak boleh dijalankan di production.');
        }

        if (TahunAjaran::query()->exists()) {
            throw new RuntimeException('Database sudah berisi data sekolah. Jalankan dulu: php artisan migrate:fresh --seed');
        }

        $this->call([
            DatabaseSeeder::class,
            SekolahDemoSeeder::class,
            KeuanganDemoSeeder::class,
            AkademikDemoSeeder::class,
            KomunikasiDemoSeeder::class,
            PpdbDemoSeeder::class,
            WebsiteDemoSeeder::class,
            AbsensiDemoSeeder::class,
        ]);
    }
}
