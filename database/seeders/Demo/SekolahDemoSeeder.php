<?php

namespace Database\Seeders\Demo;

use App\Enums\Hubungan;
use App\Enums\JenisKelamin;
use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Enums\Tingkat;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

/**
 * Tahun ajaran 2026/2027, 6 guru aktif + 2 menunggu persetujuan, 4 kelas @15 murid, dan ±45 wali murid.
 * 10 keluarga punya dua anak, 3 keluarga menautkan ayah dan ibu, 9 murid belum tertaut (kode tautan aktif).
 */
class SekolahDemoSeeder extends Seeder
{
    public const PASSWORD_GURU = 'guru2026';

    private const GURU = [
        ['Siti Rahmawati, S.Pd.', 'siti.rahmawati', true],
        ['Nur Aini, S.Pd.', 'nur.aini', false],
        ['Dwi Lestari, S.Pd.', 'dwi.lestari', false],
        ['Sri Wahyuni, S.Pd.', 'sri.wahyuni', false],
        ['Endang Susilowati, S.Pd.', 'endang.susilowati', false],
        ['Rina Kusumawati, A.Md.', 'rina.kusumawati', false],
    ];

    private const GURU_PENDING = [
        ['Fitri Handayani', 'fitri.handayani'],
        ['Ahmad Fauzi', 'ahmad.fauzi'],
    ];

    private const MURID_PER_KELAS = 15;

    private const JUMLAH_KELUARGA_DUA_ANAK = 10;

    private const JUMLAH_KELUARGA_AYAH_IBU = 3;

    private const JUMLAH_MURID_BELUM_TERTAUT = 9;

    public function run(): void
    {
        $tahunAjaran = TahunAjaran::query()->create([
            'nama' => '2026/2027',
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2027-06-25',
            'semester_aktif' => 1,
            'is_aktif' => true,
        ]);

        $guru = $this->buatGuru();
        $kelas = $this->buatKelas($tahunAjaran, $guru);
        $murid = $this->buatMurid($kelas);
        $this->buatWaliMurid($murid);
    }

    /**
     * @return list<Guru>
     */
    private function buatGuru(): array
    {
        $guru = [];

        foreach (self::GURU as [$nama, $email, $kelolaKeuangan]) {
            $guru[] = Guru::factory()
                ->for(User::factory()->create([
                    'name' => $nama,
                    'email' => $email.'@guru.tkta8.test',
                    'password' => self::PASSWORD_GURU,
                    'role' => Role::Guru,
                    'status' => StatusAkun::Aktif,
                ]))
                ->create([
                    'bisa_kelola_keuangan' => $kelolaKeuangan,
                    'tampil_di_landing' => true,
                    'disetujui_oleh' => User::query()->where('role', Role::SuperAdmin)->value('id'),
                    'disetujui_at' => '2026-06-20 09:00:00',
                ]);
        }

        foreach (self::GURU_PENDING as [$nama, $email]) {
            Guru::factory()
                ->for(User::factory()->status(StatusAkun::Pending)->create([
                    'name' => $nama,
                    'email' => $email.'@guru.tkta8.test',
                    'password' => self::PASSWORD_GURU,
                ]))
                ->create([
                    'jenis_kelamin' => $nama === 'Ahmad Fauzi' ? JenisKelamin::L : JenisKelamin::P,
                    'jabatan' => 'Guru',
                ]);
        }

        return $guru;
    }

    /**
     * @param  list<Guru>  $guru
     * @return list<Kelas>
     */
    private function buatKelas(TahunAjaran $tahunAjaran, array $guru): array
    {
        $susunan = [
            ['TK A1', Tingkat::A, $guru[1], $guru[5]],
            ['TK A2', Tingkat::A, $guru[2], null],
            ['TK B1', Tingkat::B, $guru[3], $guru[0]],
            ['TK B2', Tingkat::B, $guru[4], null],
        ];

        return array_map(fn (array $baris): Kelas => Kelas::query()->create([
            'tahun_ajaran_id' => $tahunAjaran->id,
            'nama' => $baris[0],
            'tingkat' => $baris[1],
            'wali_kelas_id' => $baris[2]->id,
            'guru_pendamping_id' => $baris[3]?->id,
            'kapasitas' => 20,
        ]), $susunan);
    }

    /**
     * Murid TK A masuk tahun ini (NIS TA2026…), murid TK B masuk tahun lalu (NIS TA2025…).
     *
     * @param  list<Kelas>  $kelas
     * @return array{A: list<Murid>, B: list<Murid>}
     */
    private function buatMurid(array $kelas): array
    {
        $murid = ['A' => [], 'B' => []];
        $nomorUrut = [2025 => 0, 2026 => 0];

        foreach ($kelas as $satuKelas) {
            $tahunMasuk = $satuKelas->tingkat === Tingkat::A ? 2026 : 2025;
            $lahirAwal = ($tahunMasuk - 4).'-07-01';
            $lahirAkhir = ($tahunMasuk - 3).'-06-30';

            for ($i = 0; $i < self::MURID_PER_KELAS; $i++) {
                $baru = Murid::factory()->create([
                    'nis' => sprintf('TA%d%04d', $tahunMasuk, ++$nomorUrut[$tahunMasuk]),
                    'tanggal_lahir' => fake()->dateTimeBetween($lahirAwal, $lahirAkhir)->format('Y-m-d'),
                    'tanggal_masuk' => $tahunMasuk === 2026 ? '2026-07-13' : '2025-07-14',
                ]);
                $satuKelas->murid()->attach($baru);
                $murid[$satuKelas->tingkat->value][] = $baru;
            }
        }

        $murid['A'][3]->update(['catatan_khusus' => 'Alergi udang dan kacang tanah.']);
        $murid['B'][8]->update(['catatan_khusus' => 'Sedang menjalani terapi wicara; mohon beri waktu lebih saat menjawab.']);

        return $murid;
    }

    /**
     * @param  array{A: list<Murid>, B: list<Murid>}  $murid
     */
    private function buatWaliMurid(array $murid): void
    {
        $keluarga = [];

        for ($i = 0; $i < self::JUMLAH_KELUARGA_DUA_ANAK; $i++) {
            $keluarga[] = [$murid['A'][$i], $murid['B'][$i]];
        }

        $sisa = [...array_slice($murid['A'], self::JUMLAH_KELUARGA_DUA_ANAK), ...array_slice($murid['B'], self::JUMLAH_KELUARGA_DUA_ANAK)];
        foreach ($sisa as $anak) {
            $keluarga[] = [$anak];
        }

        $batasTertaut = count($keluarga) - self::JUMLAH_MURID_BELUM_TERTAUT;

        foreach ($keluarga as $indeks => $anak) {
            if ($indeks >= $batasTertaut) {
                $this->beriKodeTautan(new Collection($anak));

                continue;
            }

            $hubungan = $indeks < self::JUMLAH_KELUARGA_AYAH_IBU
                ? [Hubungan::Ayah, Hubungan::Ibu]
                : [$indeks % 4 === 0 ? Hubungan::Ayah : Hubungan::Ibu];

            foreach ($hubungan as $urutan => $peran) {
                $wali = $this->buatSatuWali($peran, profilLengkap: $indeks % 17 !== 5);

                foreach ($anak as $satuAnak) {
                    $satuAnak->waliMurid()->attach($wali, ['hubungan' => $peran, 'is_kontak_utama' => $urutan === 0]);
                }
            }
        }
    }

    private function buatSatuWali(Hubungan $peran, bool $profilLengkap): WaliMurid
    {
        $nama = $peran === Hubungan::Ayah ? fake()->firstNameMale().' '.fake()->lastName() : fake()->firstNameFemale().' '.fake()->lastName();

        $factory = WaliMurid::factory()->for(User::factory()->waliMurid()->create([
            'name' => $nama,
            'email' => fake()->unique()->userName().'@wali.tkta8.test',
        ]));

        return ($profilLengkap ? $factory : $factory->profilBelumLengkap())->create();
    }

    /**
     * @param  Collection<int, Murid>  $anak
     */
    private function beriKodeTautan(Collection $anak): void
    {
        $anak->each(fn (Murid $murid) => $murid->update(Arr::only(
            Murid::factory()->denganKodeTautan()->raw(),
            ['kode_tautan', 'kode_tautan_expired_at'],
        )));
    }
}
