<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Akun Kepala Sekolah
    |--------------------------------------------------------------------------
    |
    | Dipakai SuperAdminSeeder untuk membuat satu-satunya akun super_admin.
    | Dibaca lewat config (bukan env() langsung) supaya seeder tetap bekerja
    | saat konfigurasi di-cache.
    |
    */

    'name' => env('SUPERADMIN_NAME'),

    'email' => env('SUPERADMIN_EMAIL'),

    'password' => env('SUPERADMIN_PASSWORD'),

];
