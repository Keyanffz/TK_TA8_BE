<?php

namespace Database\Seeders\Demo;

use App\Enums\Hubungan;
use App\Enums\JenisDokumen;
use App\Enums\Role;
use App\Enums\StatusMurid;
use App\Enums\StatusPendaftaran;
use App\Enums\Tingkat;
use App\Models\Murid;
use App\Models\Pendaftaran;
use App\Models\Pengaturan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use App\Services\WaliMuridService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * PPDB tahun ajaran 2027/2028 yang sedang dibuka dengan 5 pendaftar: dua adik dari wali yang sudah punya akun,
 * tiga dari pendaftar tanpa login. Status: diajukan, diajukan, diverifikasi, ditolak, diterima. Pendaftar tanpa
 * login yang diterima dibuatkan akun wali otomatis.
 */
class PpdbDemoSeeder extends Seeder
{
    private const KUOTA = 40;

    public function run(): void
    {
        $tahunAjaranTujuan = TahunAjaran::query()->create([
            'nama' => '2027/2028',
            'tanggal_mulai' => '2027-07-12',
            'tanggal_selesai' => '2028-06-23',
            'semester_aktif' => 1,
            'is_aktif' => false,
        ]);

        $this->bukaPpdb($tahunAjaranTujuan);

        $kepalaSekolah = User::query()->where('role', Role::SuperAdmin)->firstOrFail();
        $waliLama = WaliMurid::query()
            ->has('murid')
            ->where('profil_lengkap', true)
            ->whereHas('user', fn ($user) => $user->where('wajib_ganti_password', false))
            ->orderBy('id')
            ->limit(2)
            ->get();
        $pendaftar = [...$waliLama->all(), null, null, null];
        $status = [StatusPendaftaran::Diajukan, StatusPendaftaran::Diajukan, StatusPendaftaran::Diverifikasi, StatusPendaftaran::Ditolak, StatusPendaftaran::Diterima];

        foreach ($pendaftar as $urutan => $wali) {
            $pendaftaran = Pendaftaran::factory()->for($tahunAjaranTujuan)->create([
                'wali_murid_id' => $wali?->id,
                'kode' => sprintf('PPDB-2027-%04d', $urutan + 1),
                'hubungan' => $urutan % 3 === 0 ? Hubungan::Ayah : Hubungan::Ibu,
                'tingkat_tujuan' => $urutan === 2 ? Tingkat::B : Tingkat::A,
                'tanggal_lahir' => $urutan === 3 ? '2023-09-14' : fake()->dateTimeBetween('2022-07-01', '2023-06-30')->format('Y-m-d'),
                'status' => $status[$urutan],
                'catatan' => $status[$urutan] === StatusPendaftaran::Ditolak
                    ? 'Usia anak belum 4 tahun pada 1 Juli 2027. Silakan mendaftar kembali pada PPDB tahun depan.'
                    : null,
                'diproses_oleh' => $status[$urutan] === StatusPendaftaran::Diajukan ? null : $kepalaSekolah->id,
                'diproses_at' => $status[$urutan] === StatusPendaftaran::Diajukan ? null : '2026-09-21 10:00:00',
            ]);

            foreach ([JenisDokumen::AktaKelahiran, JenisDokumen::KartuKeluarga, JenisDokumen::PasFoto] as $jenis) {
                $pendaftaran->dokumen()->create([
                    'jenis' => $jenis,
                    'path' => GambarContoh::simpan('local', 'ppdb', 800, 1100),
                ]);
            }

            if ($pendaftaran->status === StatusPendaftaran::Diterima) {
                $this->jadikanMurid($pendaftaran, $kepalaSekolah);
            }
        }
    }

    private function bukaPpdb(TahunAjaran $tahunAjaranTujuan): void
    {
        $nilai = [
            'ppdb.dibuka' => true,
            'ppdb.tanggal_buka' => '2026-09-01',
            'ppdb.tanggal_tutup' => '2027-03-31',
            'ppdb.tahun_ajaran_id' => $tahunAjaranTujuan->id,
            'ppdb.kuota' => self::KUOTA,
            'ppdb.info' => '<p><strong>Syarat:</strong> usia minimal 4 tahun (Kelompok A) atau 5 tahun (Kelompok B) pada 1 Juli 2027, fotokopi akta kelahiran, fotokopi Kartu Keluarga, dan pas foto 3x4.</p>'
                .'<p><strong>Alur:</strong> isi formulir di halaman PPDB, unggah dokumen, simpan kode pendaftaran, lalu cek status pendaftaran dengan kode itu dan tanggal lahir anak. Anak yang diterima mendapat kartu akun wali murid dari sekolah.</p>',
        ];

        foreach ($nilai as $kunci => $isi) {
            Pengaturan::query()->where('kunci', $kunci)->firstOrFail()->update(['nilai' => $isi]);
        }
    }

    private function jadikanMurid(Pendaftaran $pendaftaran, User $kepalaSekolah): void
    {
        $pasFoto = $pendaftaran->dokumen()->where('jenis', JenisDokumen::PasFoto)->firstOrFail();
        $fotoMurid = 'murid/'.basename($pasFoto->path);
        Storage::disk('local')->copy($pasFoto->path, $fotoMurid);

        $murid = Murid::query()->create([
            'nis' => 'TA20270001',
            'nik' => $pendaftaran->nik,
            'nama_lengkap' => $pendaftaran->nama_lengkap,
            'nama_panggilan' => $pendaftaran->nama_panggilan,
            'jenis_kelamin' => $pendaftaran->jenis_kelamin,
            'tempat_lahir' => $pendaftaran->tempat_lahir,
            'tanggal_lahir' => $pendaftaran->tanggal_lahir,
            'agama' => $pendaftaran->agama,
            'alamat' => $pendaftaran->alamat,
            'foto_path' => $fotoMurid,
            'status' => StatusMurid::Aktif,
            'tanggal_masuk' => '2027-07-12',
        ]);

        $wali = app(WaliMuridService::class)->buatAkunOtomatis($murid, $pendaftaran->hubungan, $pendaftaran->no_hp, $kepalaSekolah);
        $pendaftaran->update(['murid_id' => $murid->id, 'wali_murid_id' => $wali->id]);
    }
}
