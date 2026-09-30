<?php

namespace App\Notifications;

use App\Models\Rapor;

/**
 * Dasar notifikasi alur rapor. Nama murid, kelas, semester, dan tahun ajaran disalin saat notifikasi dibuat
 * (butuh relasi `murid`, `kelas`, dan `tahunAjaran` pada rapor).
 */
abstract class NotifikasiRapor extends NotifikasiDatabase
{
    protected readonly int $raporId;

    protected readonly string $namaMurid;

    protected readonly string $namaPanggilan;

    protected readonly string $kelas;

    protected readonly int $semester;

    protected readonly string $tahunAjaran;

    public function __construct(Rapor $rapor)
    {
        $this->raporId = $rapor->id;
        $this->namaMurid = $rapor->murid->nama_lengkap;
        $this->namaPanggilan = $rapor->murid->nama_panggilan;
        $this->kelas = $rapor->kelas->nama;
        $this->semester = $rapor->semester;
        $this->tahunAjaran = $rapor->tahunAjaran->nama;
    }

    protected function url(object $notifiable): string
    {
        return $this->halaman($notifiable, "/rapor/{$this->raporId}");
    }
}
