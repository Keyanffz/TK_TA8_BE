<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `guru.bisa_kelola_keuangan` diganti izin modul `keuangan` level `kelola` di `izin_guru`. Profil guru milik
 * Kepala Sekolah tidak diberi baris: Kepala Sekolah selalu punya semua izin dari role-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $sekarang = now();
            $guruIds = DB::table('guru')
                ->join('users', 'users.id', '=', 'guru.user_id')
                ->where('users.role', 'guru')
                ->where('guru.bisa_kelola_keuangan', true)
                ->pluck('guru.id');

            DB::table('izin_guru')->insert($guruIds->map(fn (int $guruId): array => [
                'guru_id' => $guruId,
                'modul' => 'keuangan',
                'level' => 'kelola',
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ])->all());
        });

        Schema::table('guru', function (Blueprint $table) {
            $table->dropColumn('bisa_kelola_keuangan');
        });
    }

    /**
     * Izin lihat keuangan dan izin modul lain tidak punya padanan di kolom lama, jadi hilang saat rollback.
     */
    public function down(): void
    {
        Schema::table('guru', function (Blueprint $table) {
            $table->boolean('bisa_kelola_keuangan')->default(false)->after('foto_path');
        });

        DB::table('guru')
            ->whereIn('id', DB::table('izin_guru')->where('modul', 'keuangan')->where('level', 'kelola')->select('guru_id'))
            ->orWhereIn('user_id', DB::table('users')->where('role', 'super_admin')->select('id'))
            ->update(['bisa_kelola_keuangan' => true]);
        DB::table('izin_guru')->delete();
    }
};
