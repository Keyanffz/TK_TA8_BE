<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nama dan pekerjaan ayah/ibu serta nomor HP pendaftaran lama pindah ke `orang_tua`. Pekerjaan lama berupa teks
     * bebas, jadi masuk `pekerjaan_lainnya`. Nomor HP masuk ke orang tua sesuai `hubungan`, yang sekaligus menjadi
     * kontak utama. Murid dari PPDB tanpa login yang masih menunggu NIS ikut mendapat salinannya, supaya akun wali
     * yang dibuat saat NIS diisi tetap memakai nomor HP formulir.
     */
    public function up(): void
    {
        DB::table('pendaftaran')->orderBy('id')->chunkById(200, function ($daftar): void {
            foreach ($daftar as $pendaftaran) {
                $baris = $this->barisOrangTua($pendaftaran);
                $kontak = in_array($pendaftaran->hubungan, ['ayah', 'ibu', 'wali'], true) ? $pendaftaran->hubungan : null;

                DB::table('orang_tua')->insert(array_map(fn (array $satu): array => ['pendaftaran_id' => $pendaftaran->id, ...$satu], $baris));
                DB::table('pendaftaran')->where('id', $pendaftaran->id)->update(['kontak_utama' => $kontak]);

                $muridMenungguNis = $pendaftaran->murid_id !== null && $pendaftaran->wali_murid_id === null
                    && DB::table('murid')->where('id', $pendaftaran->murid_id)->whereNull('nis')->whereNull('deleted_at')->exists();

                if ($muridMenungguNis) {
                    DB::table('orang_tua')->insert(array_map(fn (array $satu): array => ['murid_id' => $pendaftaran->murid_id, ...$satu], $baris));
                    DB::table('murid')->where('id', $pendaftaran->murid_id)->update(['kontak_utama' => $kontak]);
                }
            }
        });

        Schema::table('pendaftaran', function (Blueprint $table) {
            $table->dropColumn(['nama_ayah', 'pekerjaan_ayah', 'nama_ibu', 'pekerjaan_ibu', 'no_hp']);
        });
    }

    public function down(): void
    {
        Schema::table('pendaftaran', function (Blueprint $table) {
            $table->string('nama_ayah')->nullable()->after('alamat');
            $table->string('pekerjaan_ayah')->nullable()->after('nama_ayah');
            $table->string('nama_ibu')->nullable()->after('pekerjaan_ayah');
            $table->string('pekerjaan_ibu')->nullable()->after('nama_ibu');
            $table->string('no_hp', 20)->nullable()->after('pekerjaan_ibu');
        });

        DB::table('pendaftaran')->orderBy('id')->chunkById(200, function ($daftar): void {
            foreach ($daftar as $pendaftaran) {
                $orangTua = DB::table('orang_tua')->where('pendaftaran_id', $pendaftaran->id)->get()->keyBy('peran');
                $ayah = $orangTua->get('ayah');
                $ibu = $orangTua->get('ibu');
                $kontak = $orangTua->get((string) $pendaftaran->kontak_utama);

                DB::table('pendaftaran')->where('id', $pendaftaran->id)->update([
                    'nama_ayah' => $ayah === null ? null : $ayah->nama,
                    'pekerjaan_ayah' => $ayah === null ? null : ($ayah->pekerjaan_lainnya ?? $ayah->pekerjaan),
                    'nama_ibu' => $ibu === null ? null : $ibu->nama,
                    'pekerjaan_ibu' => $ibu === null ? null : ($ibu->pekerjaan_lainnya ?? $ibu->pekerjaan),
                    'no_hp' => $kontak === null ? $orangTua->pluck('no_hp')->filter()->first() : $kontak->no_hp,
                ]);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function barisOrangTua(object $pendaftaran): array
    {
        $noHp = ['ayah' => null, 'ibu' => null, 'wali' => null];
        $noHp[in_array($pendaftaran->hubungan, ['ayah', 'ibu'], true) ? $pendaftaran->hubungan : 'wali'] = $pendaftaran->no_hp;
        $waktu = now();
        $baris = [];

        foreach (['ayah', 'ibu'] as $peran) {
            $nama = $pendaftaran->{"nama_{$peran}"};
            $pekerjaan = $pendaftaran->{"pekerjaan_{$peran}"};

            if ($nama !== null || $pekerjaan !== null || $noHp[$peran] !== null) {
                $baris[] = [
                    'peran' => $peran, 'hubungan' => null, 'nama' => $nama, 'pekerjaan' => $pekerjaan === null ? null : 'lainnya',
                    'pekerjaan_lainnya' => $pekerjaan === null ? null : mb_substr($pekerjaan, 0, 100), 'no_hp' => $noHp[$peran],
                    'created_at' => $waktu, 'updated_at' => $waktu,
                ];
            }
        }

        if ($noHp['wali'] !== null) {
            $baris[] = [
                'peran' => 'wali', 'hubungan' => null, 'nama' => null, 'pekerjaan' => null, 'pekerjaan_lainnya' => null,
                'no_hp' => $noHp['wali'], 'created_at' => $waktu, 'updated_at' => $waktu,
            ];
        }

        return $baris;
    }
};
