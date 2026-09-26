<?php

namespace App\Jobs;

use App\Models\Pengumuman;
use App\Notifications\PengumumanBaruNotification;
use App\Services\PengumumanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Pengumuman untuk semua bisa sampai ke ratusan akun, jadi penerima dikirimi per potongan di dalam satu job
 * antrean (B6.10), bukan satu job per penerima dari request.
 */
class KirimNotifikasiPengumuman implements ShouldQueue
{
    use Queueable;

    private const UKURAN_POTONGAN = 200;

    public function __construct(public readonly int $pengumumanId) {}

    public function handle(PengumumanService $pengumumanService): void
    {
        $pengumuman = Pengumuman::query()->with(['kelas', 'murid'])->find($this->pengumumanId);

        // Pengumuman bisa sudah dihapus atau ditarik kembali menjadi draft sebelum job ini berjalan.
        if ($pengumuman === null || $pengumuman->published_at === null) {
            return;
        }

        $notifikasi = new PengumumanBaruNotification($pengumuman);

        $pengumumanService->penerima($pengumuman)->chunkById(
            self::UKURAN_POTONGAN,
            fn (Collection $penerima) => Notification::sendNow($penerima, $notifikasi),
        );
    }
}
