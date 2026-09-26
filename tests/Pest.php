<?php

use App\Enums\StatusAkun;
use App\Models\Guru;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Akun Kepala Sekolah beserta profil gurunya, seperti hasil SuperAdminSeeder.
 */
function buatKepalaSekolah(): User
{
    $user = User::factory()->superAdmin()->create();
    Guru::factory()->for($user)->kelolaKeuangan()->create(['jabatan' => Guru::JABATAN_KEPALA_SEKOLAH]);

    return $user;
}

function buatGuru(StatusAkun $status = StatusAkun::Aktif, array $atributGuru = []): Guru
{
    return Guru::factory()->for(User::factory()->status($status))->create($atributGuru);
}
