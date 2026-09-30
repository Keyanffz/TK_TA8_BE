<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pendaftaran guru mandiri dihapus, jadi status `pending` dan `ditolak` tidak ada lagi. Akun dengan status itu
 * tidak dihapus: dijadikan `nonaktif` supaya tetap tidak bisa login, dan Kepala Sekolah bisa mengaktifkannya lewat
 * `PATCH /guru/{id}/status` kalau orangnya memang guru. Password guru dikosongkan karena guru hanya login lewat
 * Google; password Kepala Sekolah tetap. Email staff dijadikan huruf kecil supaya cocok dengan email Google.
 * Notifikasi `guru_baru` dihapus karena jenisnya tidak ada lagi di enum dan akan gagal dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $akunDinonaktifkan = DB::table('users')->whereIn('status', ['pending', 'ditolak'])->pluck('id');

            DB::table('users')->whereIn('id', $akunDinonaktifkan)->update(['status' => 'nonaktif']);
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', $akunDinonaktifkan)
                ->delete();

            $emailGuru = DB::table('users')->where('role', 'guru')->whereNotNull('email')->pluck('email');
            DB::table('password_reset_tokens')->whereIn('email', $emailGuru)->delete();
            DB::table('users')->where('role', 'guru')->update(['password' => null, 'remember_token' => null]);

            DB::table('users')->whereIn('role', ['super_admin', 'guru'])->whereNotNull('email')
                ->update(['email' => DB::raw('LOWER(email)')]);

            $notifikasiDihapus = DB::table('notifications')->where('type', 'App\\Notifications\\GuruBaruNotification')->delete();

            if ($akunDinonaktifkan->isNotEmpty() || $notifikasiDihapus > 0) {
                Log::info('Migration login Google staff: akun pending/ditolak dijadikan nonaktif.', [
                    'user_id' => $akunDinonaktifkan->all(),
                    'notifikasi_guru_baru_dihapus' => $notifikasiDihapus,
                ]);
            }
        });
    }

    /**
     * Tidak bisa dibalik: status asal (pending atau ditolak) dan hash password guru tidak disimpan. Rollback hanya
     * mengembalikan skema lewat migration lain; akun yang dinonaktifkan tetap nonaktif.
     */
    public function down(): void {}
};
