<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data wajib untuk semua environment. Data contoh ada di DemoSeeder dan dijalankan terpisah:
 * `php artisan db:seed --class=DemoSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SuperAdminSeeder::class,
            ElemenPenilaianSeeder::class,
            PengaturanSeeder::class,
        ]);
    }
}
