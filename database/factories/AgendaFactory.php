<?php

namespace Database\Factories;

use App\Enums\JenisAgenda;
use App\Models\Agenda;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agenda>
 */
class AgendaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'judul' => 'Kunjungan ke kebun binatang Semarang Zoo',
            'deskripsi' => 'Berangkat dari sekolah pukul 07.30 dengan bus. Anak memakai seragam olahraga.',
            'tanggal_mulai' => '2026-10-15',
            'tanggal_selesai' => '2026-10-15',
            'jenis' => JenisAgenda::Kegiatan,
            'is_publik' => true,
            'dibuat_oleh' => null,
        ];
    }
}
