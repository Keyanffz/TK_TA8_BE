<?php

namespace Database\Seeders\Demo;

use App\Enums\MetodeBayar;
use App\Enums\PeriodeTagihan;
use App\Enums\Role;
use App\Enums\StatusPembayaran;
use App\Enums\StatusTagihan;
use App\Enums\TipeKeringanan;
use App\Models\JenisTagihan;
use App\Models\Keringanan;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\PembayaranService;
use App\Services\TagihanService;
use App\Support\NomorUrut;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * SPP bulanan Juli–September 2026 dan uang kegiatan untuk semua murid, dengan status campuran:
 * sebagian besar lunas (ada yang lewat dua cicilan), sebagian terlambat, dan beberapa tagihan terlambat yang
 * baru dicicil. Semua pembayaran tunai dan dicatat petugas.
 */
class KeuanganDemoSeeder extends Seeder
{
    private const NOMINAL_SPP = 150000;

    private const NOMINAL_UANG_KEGIATAN = 300000;

    private const TANGGAL_JATUH_TEMPO = 10;

    private const PERIODE_SPP = ['2026-07-01', '2026-08-01', '2026-09-01'];

    /** @var list<int> */
    private array $petugasKeuangan = [];

    public function run(): void
    {
        $tahunAjaran = TahunAjaran::query()->aktif()->firstOrFail();
        $kepalaSekolah = User::query()->where('role', Role::SuperAdmin)->firstOrFail();
        $bendahara = User::query()->where('role', Role::Guru)->petugasKeuanganAktif()->firstOrFail();
        $this->petugasKeuangan = [$kepalaSekolah->id, $bendahara->id];

        $spp = JenisTagihan::query()->create([
            'tahun_ajaran_id' => $tahunAjaran->id,
            'nama' => 'SPP',
            'deskripsi' => 'Sumbangan pembinaan pendidikan bulanan.',
            'nominal' => self::NOMINAL_SPP,
            'periode' => PeriodeTagihan::Bulanan,
            'is_aktif' => true,
        ]);
        $uangKegiatan = JenisTagihan::query()->create([
            'tahun_ajaran_id' => $tahunAjaran->id,
            'nama' => 'Uang Kegiatan',
            'deskripsi' => 'Outing class, manasik haji cilik, dan pentas seni selama satu tahun ajaran.',
            'nominal' => self::NOMINAL_UANG_KEGIATAN,
            'periode' => PeriodeTagihan::Sekali,
            'is_aktif' => true,
        ]);

        $murid = Murid::query()->orderBy('id')->get();
        $keringananSpp = $this->buatKeringanan($murid, $spp, $kepalaSekolah);

        foreach (self::PERIODE_SPP as $bulanKe => $periode) {
            foreach ($murid as $urutan => $satuMurid) {
                $this->buatTagihanSpp($satuMurid, $spp, Carbon::parse($periode), $bulanKe, $urutan, TagihanService::potongan(self::NOMINAL_SPP, $keringananSpp->get($satuMurid->id)));
            }
        }

        foreach ($murid as $urutan => $satuMurid) {
            $this->buatTagihanUangKegiatan($satuMurid, $uangKegiatan, $urutan, $kepalaSekolah);
        }
    }

    /**
     * @param  Collection<int, Murid>  $murid
     * @return Collection<int, Keringanan> keringanan SPP per murid_id
     */
    private function buatKeringanan(Collection $murid, JenisTagihan $spp, User $pembuat): Collection
    {
        $daftar = [
            [$murid[4], TipeKeringanan::Persen, 50, 'Anak yatim.'],
            [$murid[11], TipeKeringanan::Nominal, 50000, 'Orang tua terdampak PHK, sesuai surat keterangan kelurahan.'],
            [$murid[25], TipeKeringanan::Persen, 100, 'Anak guru TK Tarbiyatul Athfal 8.'],
        ];
        $keringanan = [];

        foreach ($daftar as [$satuMurid, $tipe, $nilai, $alasan]) {
            $keringanan[$satuMurid->id] = Keringanan::query()->create([
                'murid_id' => $satuMurid->id,
                'jenis_tagihan_id' => $spp->id,
                'tipe' => $tipe,
                'nilai' => $nilai,
                'alasan' => $alasan,
                'berlaku_mulai' => '2026-07-01',
                'berlaku_sampai' => null,
                'dibuat_oleh' => $pembuat->id,
            ]);
        }

        return new Collection($keringanan);
    }

    private function buatTagihanSpp(Murid $murid, JenisTagihan $spp, Carbon $periode, int $bulanKe, int $urutan, int $potongan): void
    {
        $total = self::NOMINAL_SPP - $potongan;

        $rencana = match (true) {
            $total === 0 => 'gratis',
            $bulanKe === 0 => $urutan % 20 === 7 ? 'terlambat' : 'lunas',
            $bulanKe === 1 => $urutan % 10 === 7 ? 'terlambat' : 'lunas',
            $urutan % 10 === 7 => 'dicicil',
            in_array($urutan % 10, [8, 9], true) => 'terlambat',
            default => 'lunas',
        };

        $tagihan = Tagihan::query()->forceCreate([
            'kode' => $this->kodeTagihan($periode),
            'murid_id' => $murid->id,
            'jenis_tagihan_id' => $spp->id,
            'tahun_ajaran_id' => $spp->tahun_ajaran_id,
            'periode' => $periode->toDateString(),
            'nominal' => self::NOMINAL_SPP,
            'potongan' => $potongan,
            'total' => $total,
            'jatuh_tempo' => $periode->copy()->day(self::TANGGAL_JATUH_TEMPO)->toDateString(),
            'status' => StatusTagihan::BelumBayar,
            'created_at' => $periode->copy()->setTime(0, 10),
            'updated_at' => $periode->copy()->setTime(0, 10),
        ]);
        $tanggalBayar = $periode->copy()->day(2 + $urutan % 8);

        match ($rencana) {
            'gratis' => $tagihan->update(['status' => StatusTagihan::Lunas, 'lunas_at' => $tagihan->created_at]),
            'terlambat' => $tagihan->update(['status' => StatusTagihan::Terlambat]),
            'dicicil' => $this->cicil($tagihan, $periode->copy()->day(20 + $urutan % 5)),
            'lunas' => $bulanKe === 1 && $urutan % 10 === 3
                ? $this->bayarDuaKali($tagihan, $tanggalBayar)
                : $this->bayar($tagihan, $tanggalBayar, $total),
        };
    }

    /**
     * Separuh tagihan dibayar setelah jatuh tempo; sisanya masih menunggak.
     */
    private function cicil(Tagihan $tagihan, Carbon $tanggal): void
    {
        $this->bayar($tagihan, $tanggal, intdiv($tagihan->total, 2));
        $tagihan->update(['status' => StatusTagihan::Terlambat]);
    }

    private function bayarDuaKali(Tagihan $tagihan, Carbon $tanggal): void
    {
        $cicilanPertama = intdiv($tagihan->total, 2);

        $this->bayar($tagihan, $tanggal->copy()->subDay(), $cicilanPertama);
        $this->bayar($tagihan, $tanggal, $tagihan->total - $cicilanPertama);
    }

    private function buatTagihanUangKegiatan(Murid $murid, JenisTagihan $uangKegiatan, int $urutan, User $pembuat): void
    {
        $dibuat = Carbon::parse('2026-07-15 08:00:00');

        $tagihan = Tagihan::query()->forceCreate([
            'kode' => $this->kodeTagihan($dibuat),
            'murid_id' => $murid->id,
            'jenis_tagihan_id' => $uangKegiatan->id,
            'tahun_ajaran_id' => $uangKegiatan->tahun_ajaran_id,
            'periode' => null,
            'nominal' => self::NOMINAL_UANG_KEGIATAN,
            'potongan' => 0,
            'total' => self::NOMINAL_UANG_KEGIATAN,
            'jatuh_tempo' => '2026-08-31',
            'status' => StatusTagihan::BelumBayar,
            'dibuat_oleh' => $pembuat->id,
            'created_at' => $dibuat,
            'updated_at' => $dibuat,
        ]);

        if ($urutan % 6 === 0) {
            $tagihan->update(['status' => StatusTagihan::Terlambat]);

            return;
        }

        $this->bayar($tagihan, Carbon::parse('2026-07-20')->addDays($urutan % 25), self::NOMINAL_UANG_KEGIATAN);
    }

    /**
     * Tagihan lunas saat jumlah yang sudah dibayar menyamai totalnya.
     */
    private function bayar(Tagihan $tagihan, Carbon $tanggal, int $jumlah): void
    {
        $dicatat = $tanggal->copy()->setTime(9, 0);

        Pembayaran::query()->forceCreate([
            'kode' => $this->kodePembayaran($tanggal),
            'tagihan_id' => $tagihan->id,
            'metode' => MetodeBayar::Tunai,
            'jumlah' => $jumlah,
            'tanggal_bayar' => $tanggal->toDateString(),
            'status' => StatusPembayaran::Diterima,
            'diverifikasi_oleh' => fake()->randomElement($this->petugasKeuangan),
            'diverifikasi_at' => $dicatat,
            'created_at' => $dicatat,
            'updated_at' => $dicatat,
        ]);

        if ($tagihan->sisa() === 0) {
            $tagihan->update(['status' => StatusTagihan::Lunas, 'lunas_at' => $dicatat]);
        }
    }

    private function kodeTagihan(Carbon $bulan): string
    {
        $awalan = TagihanService::awalanKode($bulan);

        return NomorUrut::format($awalan, NomorUrut::berikutnya(Tagihan::query(), 'kode', $awalan, TagihanService::DIGIT_KODE), TagihanService::DIGIT_KODE);
    }

    private function kodePembayaran(Carbon $tanggal): string
    {
        $awalan = PembayaranService::awalanKode($tanggal);

        return NomorUrut::format($awalan, NomorUrut::berikutnya(Pembayaran::query(), 'kode', $awalan, PembayaranService::DIGIT_KODE), PembayaranService::DIGIT_KODE);
    }
}
