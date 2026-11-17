<?php

namespace App\Services;

use App\Enums\Hubungan;
use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Http\Requests\Auth\LoginWaliRequest;
use App\Models\Murid;
use App\Models\User;
use App\Notifications\PendaftaranDiprosesNotification;
use App\Notifications\UsernameWaliDiubahNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Excel as JenisFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;

/**
 * NIS murid diisi Kepala Sekolah dengan format bebas (A2.1). Satu murid lewat `PATCH /murid/{id}/nis`, banyak murid
 * lewat tabel di web atau file Excel hasil template; ketiganya diperiksa `periksa()` dan disimpan `isi()`. Mengisi
 * NIS murid aktif yang belum punya wali membuat akun wali otomatis. Mengubah NIS ikut mengganti username akun yang
 * memakai NIS lama.
 *
 * @phpstan-type BarisNis array{nomor: int, murid_id: int|null, nama_lengkap: string|null, nis: string|null}
 * @phpstan-type HasilNis array{nomor: int, murid_id: int|null, nama_lengkap: string|null, nis: string|null, valid: bool, akun_wali: bool, alasan: list<string>}
 * @phpstan-type HasilPeriksa array{nomor: int, murid_id: int|null, nama_lengkap: string|null, nis: string|null, valid: bool, akun_wali: bool, alasan: list<string>, murid: Murid|null}
 */
class NisMuridService
{
    public const MAKSIMAL_BARIS = 500;

    public const PANJANG_MAKSIMAL = 20;

    /** Kolom template berurutan. Import hanya membaca `id`, `nama_lengkap`, dan `nis`. */
    public const KOLOM = ['id', 'nama_lengkap', 'tanggal_lahir', 'nis'];

    /**
     * Hash bcrypt password awal sekitar 0,2 detik per akun. Batas waktu PHP dihitung ulang setiap kelompok, supaya
     * ratusan akun tidak terhenti oleh `max_execution_time`.
     */
    private const BARIS_PER_KELOMPOK = 50;

    private const DETIK_PER_KELOMPOK = 60;

    public function __construct(private readonly WaliMuridService $waliMuridService) {}

    /**
     * Sama dengan username login wali: huruf besar tanpa spasi, karena NIS menjadi username akun wali.
     */
    public static function normalkan(string $nis): string
    {
        return LoginWaliRequest::normalkanUsername($nis);
    }

    /**
     * Mengisi atau mengubah NIS satu murid dalam transaksi. Akun wali otomatis dibuat kalau murid aktif, belum punya
     * NIS, dan belum tertaut ke wali mana pun, dari kontak utama formulir; murid dari PPDB tanpa login lalu dicatat
     * dengan akun itu sebagai wali pendaftar, dan akun itu menerima notifikasi pendaftaran diterima. Notifikasi dikirim
     * setelah transaksi terluar selesai.
     *
     * @return bool akun wali dibuat
     *
     * @throws ValidationException NIS tidak lolos `periksa()`, di field `nis`
     */
    public function isi(Murid $murid, string $nis, User $pelaku, ?string $hashPasswordAwal = null): bool
    {
        return DB::transaction(function () use ($murid, $nis, $pelaku, $hashPasswordAwal): bool {
            $murid = Murid::query()->lockForUpdate()->findOrFail($murid->id);
            $hasil = $this->periksaSatu($murid->id, $nis, bolehUbah: true);

            if (! $hasil['valid']) {
                throw ValidationException::withMessages(['nis' => $hasil['alasan']]);
            }

            $nis = (string) $hasil['nis'];
            $nisLama = $murid->nis;
            $murid->update(['nis' => $nis]);

            if ($nisLama !== null) {
                $this->gantiUsername($murid, $nisLama, $pelaku);
                activity('murid')->causedBy($pelaku)->performedOn($murid)->event('nis_diubah')
                    ->withProperties(['dari' => $nisLama, 'ke' => $nis])
                    ->log("Mengubah NIS {$murid->nama_lengkap} dari {$nisLama} menjadi {$nis}");

                return false;
            }

            activity('murid')->causedBy($pelaku)->performedOn($murid)->event('nis_diisi')
                ->withProperties(['nis' => $nis, 'akun_wali_dibuat' => $hasil['akun_wali']])
                ->log("Mengisi NIS {$nis} untuk {$murid->nama_lengkap}");

            if ($hasil['akun_wali']) {
                $this->buatAkun($murid, $pelaku, $hashPasswordAwal);
            }

            return $hasil['akun_wali'];
        });
    }

    /**
     * Memeriksa banyak baris sekaligus tanpa menyimpan: format NIS, NIS dobel antarbaris (semua baris yang dobel
     * ditandai), NIS yang sudah dipakai murid lain atau username akun lain, murid yang tidak ada atau sudah punya NIS,
     * dan nama di file yang tidak sama dengan nama murid ber-ID itu. `akun_wali` true untuk baris valid yang akan
     * membuat akun wali.
     *
     * @param  list<BarisNis>  $baris
     * @return array{ringkasan: array{total: int, valid: int, invalid: int, akun_wali: int}, baris: list<HasilNis>}
     */
    public function pratinjau(array $baris): array
    {
        $hasil = array_map($this->untukRespons(...), $this->periksa($baris, bolehUbah: false));
        $valid = array_filter($hasil, fn (array $satu): bool => $satu['valid']);

        return [
            'ringkasan' => [
                'total' => count($hasil),
                'valid' => count($valid),
                'invalid' => count($hasil) - count($valid),
                'akun_wali' => count(array_filter($valid, fn (array $satu): bool => $satu['akun_wali'])),
            ],
            'baris' => $hasil,
        ];
    }

    /**
     * Memeriksa ulang, lalu mengisi NIS semua baris valid dalam satu transaksi; baris invalid dikembalikan di `gagal`.
     *
     * @param  list<BarisNis>  $baris
     * @return array{diisi: int, akun_wali_dibuat: int, gagal: list<HasilNis>}
     *
     * @throws BusinessRuleException
     */
    public function simpan(array $baris, User $pelaku): array
    {
        $hasil = $this->periksa($baris, bolehUbah: false);
        $valid = array_values(array_filter($hasil, fn (array $satu): bool => $satu['valid']));
        $gagal = array_values(array_map($this->untukRespons(...), array_filter($hasil, fn (array $satu): bool => ! $satu['valid'])));
        $batasWaktuAwal = (int) ini_get('max_execution_time');

        try {
            $hash = $this->hashPasswordAwal($valid);
            $akunDibuat = DB::transaction(function () use ($valid, $hash, $pelaku): int {
                $jumlah = 0;

                foreach ($valid as $satu) {
                    $jumlah += (int) $this->isi($this->muridDari($satu), (string) $satu['nis'], $pelaku, $hash[$satu['nomor']] ?? null);
                }

                return $jumlah;
            });
        } catch (UniqueConstraintViolationException|ValidationException) {
            throw new BusinessRuleException('Ada NIS yang baru saja dipakai data lain saat menyimpan, jadi tidak ada NIS yang disimpan. Periksa ulang datanya lalu simpan lagi.');
        } finally {
            set_time_limit($batasWaktuAwal);
        }

        return ['diisi' => count($valid), 'akun_wali_dibuat' => $akunDibuat, 'gagal' => $gagal];
    }

    /**
     * Sheet pertama file .xlsx hasil template, judul kolom di baris 1. Kolom selain `id`, `nama_lengkap`, dan `nis`
     * diabaikan; baris yang ketiganya kosong dilewati.
     *
     * @return list<BarisNis>
     *
     * @throws ValidationException
     */
    public function baca(UploadedFile $file): array
    {
        try {
            $sel = Excel::toArray(new class implements Import {}, $file, null, JenisFile::XLSX)[0] ?? [];
        } catch (SpreadsheetException) {
            throw ValidationException::withMessages(['file' => 'File tidak bisa dibaca. Pakai file .xlsx dari tombol Unduh Daftar Murid.']);
        }

        $judul = array_map(fn (mixed $isi): string => Str::slug(trim(is_scalar($isi) ? (string) $isi : ''), '_'), array_shift($sel) ?? []);
        $kurang = array_diff(['id', 'nama_lengkap', 'nis'], $judul);

        if ($kurang !== []) {
            throw ValidationException::withMessages(['file' => 'Kolom '.implode(', ', $kurang).' tidak ada di file. Pakai file dari tombol Unduh Daftar Murid.']);
        }

        $baris = [];
        foreach ($sel as $indeks => $isiBaris) {
            [$id, $nama, $nis] = array_map(fn (string $kolom): ?string => $this->teks($isiBaris[array_search($kolom, $judul, true)] ?? null), ['id', 'nama_lengkap', 'nis']);

            if ($id !== null || $nama !== null || $nis !== null) {
                // Baris pertama data adalah baris kedua di spreadsheet (baris pertama berisi judul kolom).
                $baris[] = ['nomor' => (int) $indeks + 2, 'murid_id' => $id !== null && ctype_digit($id) ? (int) $id : null, 'nama_lengkap' => $nama, 'nis' => $nis];
            }
        }

        if ($baris === []) {
            throw ValidationException::withMessages(['file' => 'File tidak berisi data murid. Isi NIS mulai baris kedua, di bawah judul kolom.']);
        }
        if (count($baris) > self::MAKSIMAL_BARIS) {
            throw ValidationException::withMessages(['file' => 'File berisi '.count($baris).' baris, sedangkan satu kali simpan maksimal '.self::MAKSIMAL_BARIS.' baris. Bagi menjadi beberapa file.']);
        }

        return $baris;
    }

    /**
     * Murid aktif yang menunggu NIS, urut nama, untuk template Excel.
     *
     * @return Collection<int, Murid>
     */
    public function menungguNis(): Collection
    {
        return Murid::query()->whereNull('nis')->where('status', StatusMurid::Aktif)->orderBy('nama_lengkap')->get(['id', 'nama_lengkap', 'tanggal_lahir']);
    }

    /**
     * @return HasilPeriksa
     */
    private function periksaSatu(int $muridId, string $nis, bool $bolehUbah): array
    {
        return $this->periksa([['nomor' => 1, 'murid_id' => $muridId, 'nama_lengkap' => null, 'nis' => $nis]], $bolehUbah)[0];
    }

    /**
     * @param  list<BarisNis>  $baris
     * @return list<HasilPeriksa>
     */
    private function periksa(array $baris, bool $bolehUbah): array
    {
        $baris = array_map(fn (array $satu): array => [...$satu, 'nis' => $satu['nis'] === null ? null : self::normalkan($satu['nis'])], $baris);
        $nis = array_values(array_filter(array_column($baris, 'nis')));
        $murid = Murid::query()->withCount('waliMurid')->findMany(array_filter(array_column($baris, 'murid_id')))->keyBy('id');
        $pemilikNis = Murid::withTrashed()->whereIn('nis', $nis)->get(['id', 'nis', 'nama_lengkap'])->keyBy(fn (Murid $satu): string => Str::upper((string) $satu->nis));
        $username = User::withTrashed()->whereIn('username', $nis)->pluck('username')->map(fn (?string $satu): string => Str::upper((string) $satu))->flip();
        $penandaPerNis = [];

        foreach ($baris as $satu) {
            if ($satu['nis'] !== null) {
                $penandaPerNis[$satu['nis']][$satu['nomor']] = $this->penandaBaris($satu, $satu['murid_id'] === null ? null : $murid->get($satu['murid_id']));
            }
        }

        return array_map(function (array $satu) use ($murid, $pemilikNis, $username, $penandaPerNis, $bolehUbah): array {
            $data = $satu['murid_id'] === null ? null : $murid->get($satu['murid_id']);
            $alasan = [
                ...$this->alasanMurid($satu, $data, $bolehUbah),
                ...$this->alasanNis($satu, $data, $pemilikNis->all(), $username->all(), $penandaPerNis),
            ];
            $valid = $alasan === [];

            return [
                ...$satu,
                'valid' => $valid,
                'akun_wali' => $valid && $data !== null && $data->nis === null && $data->status === StatusMurid::Aktif && (int) $data->getAttribute('wali_murid_count') === 0,
                'alasan' => $alasan,
                'murid' => $data,
            ];
        }, $baris);
    }

    /**
     * @param  BarisNis  $baris
     * @return list<string>
     */
    private function alasanMurid(array $baris, ?Murid $murid, bool $bolehUbah): array
    {
        return match (true) {
            $baris['murid_id'] === null => ['ID murid kosong atau bukan angka. Jangan ubah kolom id dari file yang diunduh.'],
            $murid === null => ["Murid dengan ID {$baris['murid_id']} tidak ditemukan."],
            ! $bolehUbah && $murid->nis !== null => ["{$murid->nama_lengkap} sudah punya NIS {$murid->nis}."],
            $baris['nama_lengkap'] !== null && $this->kunciNama($baris['nama_lengkap']) !== $this->kunciNama($murid->nama_lengkap) => [
                "Nama {$baris['nama_lengkap']} tidak sama dengan murid ID {$murid->id} ({$murid->nama_lengkap}). Periksa apakah kolom id tergeser.",
            ],
            default => [],
        };
    }

    /**
     * @param  BarisNis  $baris
     * @param  array<string, Murid>  $pemilikNis  murid per NIS, termasuk murid yang dihapus
     * @param  array<string, int>  $username  username akun yang sudah ada
     * @param  array<string, array<int, string>>  $penandaPerNis  penanda baris per NIS, dikunci nomor baris
     * @return list<string>
     */
    private function alasanNis(array $baris, ?Murid $murid, array $pemilikNis, array $username, array $penandaPerNis): array
    {
        $nis = $baris['nis'];

        if ($nis === null || $nis === '') {
            return ['NIS wajib diisi.'];
        }
        if (Str::length($nis) > self::PANJANG_MAKSIMAL) {
            return ['NIS maksimal '.self::PANJANG_MAKSIMAL.' karakter.'];
        }
        if (preg_match('#^[A-Z0-9./-]+$#', $nis) !== 1) {
            return ['NIS hanya boleh berisi huruf, angka, titik, strip, dan garis miring.'];
        }

        $barisLain = array_diff_key($penandaPerNis[$nis] ?? [], [$baris['nomor'] => true]);
        $pemilik = $pemilikNis[$nis] ?? null;

        return match (true) {
            $barisLain !== [] => ["NIS {$nis} dobel dengan ".implode(', ', $barisLain).'.'],
            $pemilik !== null && $pemilik->id === $murid?->id => ['NIS ini sama dengan NIS sekarang.'],
            $pemilik !== null => ["NIS {$nis} sudah dipakai {$pemilik->nama_lengkap}."],
            isset($username[$nis]) => ["NIS {$nis} sudah dipakai sebagai username akun wali murid lain."],
            default => [],
        };
    }

    /**
     * Cara menyebut baris lain di pesan NIS dobel: nama murid, ditambah nomor baris untuk file Excel. Tabel web tidak
     * menampilkan nomor baris, jadi cukup nama.
     *
     * @param  BarisNis  $baris
     */
    private function penandaBaris(array $baris, ?Murid $murid): string
    {
        $nama = $murid->nama_lengkap ?? $baris['nama_lengkap'];

        return match (true) {
            $nama === null => "baris {$baris['nomor']}",
            $baris['nama_lengkap'] === null => $nama,
            default => "{$nama} (baris {$baris['nomor']})",
        };
    }

    /**
     * Hubungan, nama, dan nomor HP akun diambil dari kontak utama formulir; murid tanpa kontak utama (data lama)
     * mendapat hubungan `wali` tanpa nomor HP.
     */
    private function buatAkun(Murid $murid, User $pelaku, ?string $hashPasswordAwal): void
    {
        $kontak = $murid->kontak_utama === null ? null : $murid->orangTua()->where('peran', $murid->kontak_utama)->first();
        $wali = $this->waliMuridService->buatAkunOtomatis($murid, $murid->kontak_utama ?? Hubungan::Wali, $kontak?->no_hp, $kontak?->nama, $pelaku, $hashPasswordAwal);
        $pendaftaran = $murid->pendaftaran()->whereNull('wali_murid_id')->first();

        if ($pendaftaran !== null) {
            $pendaftaran->update(['wali_murid_id' => $wali->id]);
            $notifikasi = new PendaftaranDiprosesNotification($pendaftaran->load('murid.kelas'));
            DB::afterCommit(fn () => $wali->user->notify($notifikasi));
        }
    }

    /**
     * Akun yang username-nya NIS lama murid ini (akun otomatisnya, termasuk yang sudah dinonaktifkan) ikut memakai NIS
     * baru. Akun keluarga yang username-nya NIS kakak/adik tidak tersentuh.
     */
    private function gantiUsername(Murid $murid, string $nisLama, User $pelaku): void
    {
        $akun = User::query()->where('role', Role::WaliMurid)->where('username', $nisLama)->first();

        if ($akun === null) {
            return;
        }

        $akun->update(['username' => $murid->nis]);
        activity('akun')->causedBy($pelaku)->performedOn($akun)->event('username_diubah')
            ->withProperties(['murid_id' => $murid->id, 'dari' => $nisLama, 'ke' => $murid->nis])
            ->log("Mengganti username wali murid {$nisLama} menjadi {$murid->nis} karena NIS {$murid->nama_lengkap} diubah");

        if ($akun->status === StatusAkun::Aktif) {
            $notifikasi = new UsernameWaliDiubahNotification($murid->nama_panggilan, (string) $murid->nis);
            DB::afterCommit(fn () => $akun->notify($notifikasi));
        }
    }

    /**
     * Di luar transaksi, supaya transaksi tidak menahan kunci selama hashing berjalan.
     *
     * @param  list<HasilPeriksa>  $valid
     * @return array<int, string> hash password awal per nomor baris yang akan membuat akun wali
     */
    private function hashPasswordAwal(array $valid): array
    {
        $hash = [];

        foreach (array_chunk(array_filter($valid, fn (array $satu): bool => $satu['akun_wali']), self::BARIS_PER_KELOMPOK) as $kelompok) {
            set_time_limit(self::DETIK_PER_KELOMPOK);

            foreach ($kelompok as $satu) {
                $hash[$satu['nomor']] = Hash::make($this->muridDari($satu)->passwordAwalWali());
            }
        }

        return $hash;
    }

    /**
     * @param  HasilPeriksa  $hasil
     */
    private function muridDari(array $hasil): Murid
    {
        return $hasil['murid'] ?? throw new LogicException('Baris valid selalu punya murid.');
    }

    private function teks(mixed $nilai): ?string
    {
        if (is_float($nilai) && floor($nilai) === $nilai) {
            $nilai = (int) $nilai;
        }

        $teks = trim((string) preg_replace('/\s+/u', ' ', is_scalar($nilai) ? (string) $nilai : ''));

        return $teks === '' ? null : $teks;
    }

    private function kunciNama(string $nama): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', $nama)));
    }

    /**
     * @param  HasilPeriksa  $hasil
     * @return HasilNis
     */
    private function untukRespons(array $hasil): array
    {
        unset($hasil['murid']);

        return $hasil;
    }
}
