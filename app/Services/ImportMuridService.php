<?php

namespace App\Services;

use App\Enums\Hubungan;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Http\Requests\Murid\SimpanMuridRequest;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Excel as JenisFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;

/**
 * Import murid dari file Excel/CSV hasil template (A2.14). Pratinjau hanya memeriksa; simpan memeriksa ulang (data
 * bisa berubah sejak pratinjau) lalu menyimpan semua baris valid dalam satu transaksi. Murid aktif dibuatkan akun
 * wali otomatis seperti `POST /murid` dan bisa langsung ditempatkan di kelas tahun ajaran aktif; murid lulus, pindah,
 * dan keluar disimpan tanpa akun wali, sehingga juga tidak ikut generate tagihan. Duplikat dideteksi lewat NISN, atau
 * lewat nama lengkap + tanggal lahir untuk baris tanpa NISN.
 *
 * @phpstan-type IsiBaris array<string, string|null>
 * @phpstan-type BarisPratinjau array{nomor: int, data: IsiBaris, valid: bool, alasan: list<string>}
 * @phpstan-type HasilPeriksa array{nomor: int, data: IsiBaris, valid: bool, alasan: list<string>, kelas: Kelas|null, tanggal_keluar: string|null}
 */
class ImportMuridService
{
    /** Kolom template berurutan beserta [wajib, petunjuk], ditulis di sheet Petunjuk template. */
    public const KOLOM = [
        'nama_lengkap' => ['Ya', 'Nama lengkap murid.'],
        'nama_panggilan' => ['Ya', 'Nama panggilan, ditulis di kartu akun wali.'],
        'nisn' => ['Tidak', '10 digit. Baris dengan NISN yang sudah terdaftar dilewati dan dilaporkan.'],
        'nik' => ['Tidak', '16 digit. Tulis sebagai teks (kolom template sudah berformat teks) supaya digit terakhir tidak berubah.'],
        'jenis_kelamin' => ['Ya', 'L atau P.'],
        'tempat_lahir' => ['Ya', 'Kota tempat lahir.'],
        'tanggal_lahir' => ['Ya', 'YYYY-MM-DD, misalnya 2021-05-14 (DD/MM/YYYY juga diterima). Untuk murid aktif, ini juga password awal akun wali (DDMMYYYY).'],
        'agama' => ['Ya', 'Misalnya Islam.'],
        'alamat' => ['Ya', 'Alamat rumah.'],
        'anak_ke' => ['Tidak', 'Angka 1 sampai 20.'],
        'catatan_khusus' => ['Tidak', 'Misalnya alergi makanan atau kebutuhan khusus.'],
        'tanggal_masuk' => ['Ya', 'YYYY-MM-DD. Tahunnya dipakai untuk NIS.'],
        'status' => ['Tidak', 'aktif, lulus, pindah, atau keluar; kosong berarti aktif. Murid lulus, pindah, dan keluar tidak dibuatkan akun wali dan tidak ditagih.'],
        'tanggal_status' => ['Tidak', 'Tanggal lulus, pindah, atau keluar (YYYY-MM-DD); kosong untuk murid aktif. Kosong untuk murid lulus berarti akhir tahun ajaran di tahun lulus.'],
        'tahun_lulus' => ['Untuk status lulus', 'Tahun lulus, misalnya 2019.'],
        'sekolah_tujuan' => ['Tidak', 'Hanya untuk status pindah.'],
        'keterangan' => ['Tidak', 'Keterangan status, hanya untuk murid lulus, pindah, atau keluar.'],
        'kelas' => ['Tidak', 'Hanya untuk murid aktif: nama kelas di tahun ajaran aktif, misalnya TK A1. Murid aktif tanpa kelas belum ikut tagihan SPP sampai ditempatkan.'],
    ];

    public const MAKSIMAL_BARIS = 500;

    /**
     * Hash bcrypt password awal sekitar 0,2 detik per murid aktif. Batas waktu PHP dihitung ulang dari nol setiap
     * kelompok, supaya import ratusan murid aktif tidak terhenti oleh `max_execution_time`.
     */
    private const BARIS_PER_KELOMPOK = 50;

    private const DETIK_PER_KELOMPOK = 60;

    private const KOLOM_TANGGAL = ['tanggal_lahir', 'tanggal_masuk', 'tanggal_status'];

    private const KOLOM_DATA_DIRI = [
        'nisn', 'nik', 'nama_lengkap', 'nama_panggilan', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'agama', 'alamat',
        'anak_ke', 'catatan_khusus', 'tanggal_masuk',
    ];

    private const PESAN = [
        'jenis_kelamin.enum' => 'Jenis kelamin diisi L atau P.',
        'status.enum' => 'Status diisi aktif, lulus, pindah, atau keluar.',
        'tanggal_lahir.date_format' => 'Tanggal lahir harus berformat YYYY-MM-DD, misalnya 2021-05-14.',
        'tanggal_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
        'tanggal_masuk.date_format' => 'Tanggal masuk harus berformat YYYY-MM-DD, misalnya 2025-07-14.',
        'tanggal_status.date_format' => 'Tanggal status harus berformat YYYY-MM-DD.',
        'tanggal_status.before_or_equal' => 'Tanggal status tidak boleh setelah hari ini.',
        'tanggal_status.after_or_equal' => 'Tanggal status tidak boleh sebelum tanggal masuk.',
        'tanggal_status.prohibited_if' => 'Tanggal status dikosongkan untuk murid aktif.',
        'tahun_lulus.required_if' => 'Tahun lulus wajib diisi untuk murid yang lulus.',
        'tahun_lulus.prohibited_unless' => 'Tahun lulus hanya diisi untuk murid yang lulus.',
        'sekolah_tujuan.prohibited_unless' => 'Sekolah tujuan hanya diisi untuk murid yang pindah.',
        'keterangan.prohibited_if' => 'Keterangan hanya diisi untuk murid lulus, pindah, atau keluar.',
        'kelas.prohibited_unless' => 'Kelas hanya diisi untuk murid aktif.',
    ];

    public function __construct(
        private readonly MuridService $muridService,
        private readonly WaliMuridService $waliMuridService,
        private readonly KelasService $kelasService,
        private readonly StatusMuridService $statusMurid,
    ) {}

    /**
     * Memeriksa setiap baris tanpa menyimpan apa pun.
     *
     * @return array{ringkasan: array{total: int, valid: int, invalid: int}, baris: list<BarisPratinjau>}
     *
     * @throws ValidationException
     */
    public function pratinjau(UploadedFile $file): array
    {
        $baris = array_map($this->untukRespons(...), $this->periksa($this->baca($file)));
        $valid = count(array_filter($baris, fn (array $satu): bool => $satu['valid']));

        return [
            'ringkasan' => ['total' => count($baris), 'valid' => $valid, 'invalid' => count($baris) - $valid],
            'baris' => $baris,
        ];
    }

    /**
     * Baris invalid tidak disimpan dan dikembalikan sebagai `gagal` beserta alasannya.
     *
     * @return array{dibuat: int, akun_wali_dibuat: int, gagal: list<BarisPratinjau>}
     *
     * @throws ValidationException
     * @throws BusinessRuleException
     */
    public function simpan(UploadedFile $file, User $pelaku): array
    {
        $hasil = $this->periksa($this->baca($file));
        $valid = array_values(array_filter($hasil, fn (array $satu): bool => $satu['valid']));
        $gagal = array_values(array_map($this->untukRespons(...), array_filter($hasil, fn (array $satu): bool => ! $satu['valid'])));
        $akunWali = 0;
        $batasWaktuAwal = (int) ini_get('max_execution_time');

        try {
            if ($valid !== []) {
                $hashPassword = $this->hashPasswordAwal($valid);
                $akunWali = DB::transaction(fn (): int => $this->simpanBaris($valid, $hashPassword, $pelaku));
            }
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('Ada NISN atau NIS yang baru saja dipakai data lain saat import berjalan, jadi tidak ada murid yang disimpan. Unggah ulang file untuk memeriksa datanya lagi.');
        } finally {
            set_time_limit($batasWaktuAwal);
        }

        if ($valid !== []) {
            activity('murid')->causedBy($pelaku)->event('diimpor')
                ->withProperties(['nama_file' => $file->getClientOriginalName(), 'dibuat' => count($valid), 'akun_wali_dibuat' => $akunWali, 'gagal' => count($gagal)])
                ->log('Mengimpor '.count($valid)." murid dari {$file->getClientOriginalName()}");
        }

        return ['dibuat' => count($valid), 'akun_wali_dibuat' => $akunWali, 'gagal' => $gagal];
    }

    /**
     * Sheet pertama, baris pertama sebagai judul kolom. Kolom di luar template diabaikan (misalnya kolom `alasan`
     * dari laporan baris gagal), jadi laporan itu bisa diperbaiki lalu diimpor ulang.
     *
     * @return list<array{nomor: int, data: IsiBaris}>
     *
     * @throws ValidationException
     */
    private function baca(UploadedFile $file): array
    {
        $jenis = Str::lower($file->getClientOriginalExtension()) === 'csv' ? JenisFile::CSV : JenisFile::XLSX;

        try {
            $sel = Excel::toArray(new class implements Import {}, $file, null, $jenis)[0] ?? [];
        } catch (SpreadsheetException) {
            throw ValidationException::withMessages(['file' => 'File tidak bisa dibaca. Pastikan file berformat .xlsx atau .csv, misalnya hasil isian template.']);
        }

        $judul = array_map(fn (mixed $isi): string => Str::slug(trim(is_scalar($isi) ? (string) $isi : ''), '_'), array_shift($sel) ?? []);
        $wajib = array_keys(array_filter(self::KOLOM, fn (array $kolom): bool => $kolom[0] === 'Ya'));
        $kurang = array_diff($wajib, $judul);

        if ($kurang !== []) {
            throw ValidationException::withMessages(['file' => 'Kolom wajib tidak ada di file: '.implode(', ', $kurang).'. Pakai template dari tombol Unduh Template.']);
        }

        $baris = [];
        foreach ($sel as $indeks => $isiBaris) {
            $data = [];
            foreach (array_keys(self::KOLOM) as $kolom) {
                $posisi = array_search($kolom, $judul, true);
                $data[$kolom] = $posisi === false ? null : $this->teks($isiBaris[$posisi] ?? null, in_array($kolom, self::KOLOM_TANGGAL, true));
            }

            if (array_filter($data, fn (?string $nilai): bool => $nilai !== null) !== []) {
                $data['status'] = Str::lower($data['status'] ?? StatusMurid::Aktif->value);
                $data['jenis_kelamin'] = $data['jenis_kelamin'] === null ? null : Str::upper($data['jenis_kelamin']);
                // Baris pertama data adalah baris kedua di spreadsheet (baris pertama berisi judul kolom).
                $baris[] = ['nomor' => (int) $indeks + 2, 'data' => $data];
            }
        }

        if ($baris === []) {
            throw ValidationException::withMessages(['file' => 'File tidak berisi data murid. Isi data mulai baris kedua, di bawah judul kolom.']);
        }
        if (count($baris) > self::MAKSIMAL_BARIS) {
            throw ValidationException::withMessages(['file' => 'File berisi '.count($baris).' baris, sedangkan satu import maksimal '.self::MAKSIMAL_BARIS.' baris. Bagi menjadi beberapa file.']);
        }

        return $baris;
    }

    /**
     * Angka dari Excel (tanggal berupa nomor seri, NISN tanpa format teks) diubah ke teks; spasi berlebih dibuang.
     */
    private function teks(mixed $nilai, bool $tanggal): ?string
    {
        if ($tanggal && (is_int($nilai) || is_float($nilai))) {
            return Carbon::instance(TanggalExcel::excelToDateTimeObject($nilai))->toDateString();
        }
        if (is_float($nilai) && floor($nilai) === $nilai) {
            $nilai = (int) $nilai;
        }

        $teks = trim((string) preg_replace('/\s+/u', ' ', is_scalar($nilai) ? (string) $nilai : ''));

        if ($teks === '') {
            return null;
        }

        return $tanggal ? $this->tanggal($teks) : $teks;
    }

    /**
     * Tanggal yang tidak cocok dengan format mana pun dikembalikan apa adanya, lalu ditolak validasi format.
     */
    private function tanggal(string $teks): string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $hasil = DateTimeImmutable::createFromFormat('!'.$format, $teks);

            if ($hasil !== false && DateTimeImmutable::getLastErrors() === false) {
                return $hasil->format('Y-m-d');
            }
        }

        return $teks;
    }

    /**
     * @param  list<array{nomor: int, data: IsiBaris}>  $baris
     * @return list<HasilPeriksa>
     */
    private function periksa(array $baris): array
    {
        $isi = array_column($baris, 'data');
        $kelas = Kelas::query()->whereHas('tahunAjaran', fn (Builder $tahunAjaran) => $tahunAjaran->aktif())->withCount('muridAktif')->get()
            ->keyBy(fn (Kelas $satu): string => Str::lower($satu->nama));
        $sisaTempat = $kelas->map(fn (Kelas $satu): int => $satu->kapasitas - (int) $satu->getAttribute('murid_aktif_count'))->all();
        $nisnTerdaftar = Murid::withTrashed()->whereIn('nisn', array_filter(array_column($isi, 'nisn')))->pluck('nama_lengkap', 'nisn');
        $namaTerdaftar = Murid::query()->whereIn('nama_lengkap', array_filter(array_column($isi, 'nama_lengkap')))->get(['nama_lengkap', 'tanggal_lahir'])
            ->mapWithKeys(fn (Murid $murid): array => [$this->kunciNama($murid->nama_lengkap, $murid->tanggal_lahir->toDateString()) => true]);
        $barisSebelumnya = [];
        $hasil = [];

        foreach ($baris as ['nomor' => $nomor, 'data' => $data]) {
            $alasan = $this->alasanIsian($data);
            $kunci = $data['nisn'] !== null ? 'nisn:'.$data['nisn'] : 'nama:'.$this->kunciNama($data['nama_lengkap'], $data['tanggal_lahir']);
            $alasan = [...$alasan, ...$this->alasanGanda($data, $barisSebelumnya[$kunci] ?? null, $nisnTerdaftar->all(), $namaTerdaftar->all())];
            $barisSebelumnya[$kunci] ??= $nomor;

            $tanggalKeluar = $alasan === [] ? $this->tanggalKeluar($data, $alasan) : null;
            $kelasTujuan = $alasan === [] && $data['kelas'] !== null ? $this->kelasTujuan($data['kelas'], $kelas, $sisaTempat, $alasan) : null;

            if ($kelasTujuan !== null) {
                $sisaTempat[Str::lower($kelasTujuan->nama)]--;
            }

            $hasil[] = ['nomor' => $nomor, 'data' => $data, 'valid' => $alasan === [], 'alasan' => $alasan, 'kelas' => $kelasTujuan, 'tanggal_keluar' => $tanggalKeluar];
        }

        return $hasil;
    }

    /**
     * @param  IsiBaris  $data
     * @return list<string>
     */
    private function alasanIsian(array $data): array
    {
        $lulus = StatusMurid::Lulus->value;
        $aktif = StatusMurid::Aktif->value;

        return array_values(Validator::make($data, [
            ...SimpanMuridRequest::aturanDataDiri(),
            'nisn' => ['nullable', 'digits:10'],
            'status' => ['required', Rule::enum(StatusMurid::class)],
            'tanggal_status' => ['bail', 'nullable', 'prohibited_if:status,'.$aktif, 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:tanggal_masuk'],
            'tahun_lulus' => [
                'nullable', 'required_if:status,'.$lulus, 'prohibited_unless:status,'.$lulus,
                'integer', 'min:'.StatusMuridService::TAHUN_LULUS_PALING_AWAL, 'max:'.now()->year,
            ],
            'sekolah_tujuan' => ['nullable', 'prohibited_unless:status,'.StatusMurid::Pindah->value, 'string', 'max:150'],
            'keterangan' => ['nullable', 'prohibited_if:status,'.$aktif, 'string', 'max:500'],
            'kelas' => ['nullable', 'prohibited_unless:status,'.$aktif, 'string'],
        ], self::PESAN)->errors()->all());
    }

    /**
     * @param  IsiBaris  $data
     * @param  array<string, string>  $nisnTerdaftar  nama murid per NISN, termasuk murid yang dihapus
     * @param  array<string, bool>  $namaTerdaftar
     * @return list<string>
     */
    private function alasanGanda(array $data, ?int $barisSebelumnya, array $nisnTerdaftar, array $namaTerdaftar): array
    {
        $nisn = $data['nisn'];

        return match (true) {
            $nisn !== null && isset($nisnTerdaftar[$nisn]) => ["NISN {$nisn} sudah dipakai {$nisnTerdaftar[$nisn]}."],
            $nisn !== null && $barisSebelumnya !== null => ["NISN {$nisn} sama dengan baris {$barisSebelumnya}."],
            $nisn === null && isset($namaTerdaftar[$this->kunciNama($data['nama_lengkap'], $data['tanggal_lahir'])]) => [
                "Murid bernama {$data['nama_lengkap']} dengan tanggal lahir yang sama sudah terdaftar. Isi NISN kalau ini murid yang berbeda.",
            ],
            $nisn === null && $barisSebelumnya !== null => ["Nama lengkap dan tanggal lahir sama dengan baris {$barisSebelumnya}."],
            default => [],
        };
    }

    /**
     * Tanggal status yang disimpan di `tanggal_keluar`. Alumni lulus tanpa tanggal memakai akhir tahun ajaran di
     * tahun lulusnya; pindah dan keluar tanpa tanggal disimpan tanpa tanggal.
     *
     * @param  IsiBaris  $data
     * @param  list<string>  $alasan
     */
    private function tanggalKeluar(array $data, array &$alasan): ?string
    {
        if ($data['status'] !== StatusMurid::Lulus->value) {
            return $data['tanggal_status'];
        }

        $tahunLulus = (int) $data['tahun_lulus'];
        $tanggalMasuk = Carbon::parse((string) $data['tanggal_masuk']);

        if ($tahunLulus < $tanggalMasuk->year) {
            $alasan[] = "Tahun lulus tidak boleh sebelum tahun masuk ({$tanggalMasuk->year}).";

            return null;
        }
        if ($data['tanggal_status'] !== null) {
            return $data['tanggal_status'];
        }

        $bawaan = $this->statusMurid->tanggalLulusBawaan($tahunLulus);

        if ($bawaan->lt($tanggalMasuk)) {
            $alasan[] = "Tanggal lulus bawaan ({$bawaan->translatedFormat('j F Y')}) sebelum tanggal masuk. Isi tanggal_status.";

            return null;
        }

        return $bawaan->toDateString();
    }

    /**
     * @param  Collection<string, Kelas>  $kelas  kelas tahun ajaran aktif per nama huruf kecil
     * @param  array<string, int>  $sisaTempat
     * @param  list<string>  $alasan
     */
    private function kelasTujuan(string $nama, Collection $kelas, array $sisaTempat, array &$alasan): ?Kelas
    {
        $kunci = Str::lower($nama);
        $tujuan = $kelas->get($kunci);

        if ($tujuan === null) {
            $alasan[] = "Kelas {$nama} tidak ada di tahun ajaran aktif.";
        } elseif ($sisaTempat[$kunci] <= 0) {
            $alasan[] = "Kelas {$tujuan->nama} sudah penuh (kapasitas {$tujuan->kapasitas}).";
        }

        return $alasan === [] ? $tujuan : null;
    }

    private function kunciNama(?string $nama, ?string $tanggalLahir): string
    {
        return Str::lower((string) $nama).'|'.$tanggalLahir;
    }

    /**
     * Di luar transaksi, supaya transaksi tidak menahan kunci selama hashing berjalan.
     *
     * @param  list<HasilPeriksa>  $valid
     * @return array<int, string> hash password awal per nomor baris murid aktif
     */
    private function hashPasswordAwal(array $valid): array
    {
        $aktif = array_filter($valid, fn (array $satu): bool => $satu['data']['status'] === StatusMurid::Aktif->value);
        $hash = [];

        foreach (array_chunk($aktif, self::BARIS_PER_KELOMPOK) as $kelompok) {
            set_time_limit(self::DETIK_PER_KELOMPOK);

            foreach ($kelompok as $satu) {
                $hash[$satu['nomor']] = Hash::make(Murid::passwordAwalDari(Carbon::parse((string) $satu['data']['tanggal_lahir'])));
            }
        }

        return $hash;
    }

    /**
     * @param  list<HasilPeriksa>  $valid
     * @param  array<int, string>  $hashPassword
     * @return int jumlah akun wali yang dibuat
     *
     * @throws BusinessRuleException
     */
    private function simpanBaris(array $valid, array $hashPassword, User $pelaku): int
    {
        $penempatan = [];
        $akunWali = 0;

        foreach (array_chunk($valid, self::BARIS_PER_KELOMPOK) as $kelompok) {
            set_time_limit(self::DETIK_PER_KELOMPOK);

            foreach ($kelompok as $satu) {
                $murid = $this->muridService->buatDenganFotoTersimpan($this->dataMurid($satu), null);

                if ($murid->status === StatusMurid::Aktif) {
                    $this->waliMuridService->buatAkunOtomatis($murid, Hubungan::Wali, null, $pelaku, $hashPassword[$satu['nomor']]);
                    $akunWali++;
                }
                if ($satu['kelas'] !== null) {
                    $penempatan[$satu['kelas']->id][] = $murid->id;
                }
            }
        }

        foreach ($penempatan as $kelasId => $muridIds) {
            $this->kelasService->tempatkanMurid(Kelas::query()->findOrFail($kelasId), $muridIds);
        }

        return $akunWali;
    }

    /**
     * @param  HasilPeriksa  $baris
     * @return array<string, mixed>
     */
    private function dataMurid(array $baris): array
    {
        $data = $baris['data'];
        $status = StatusMurid::from((string) $data['status']);

        return [
            ...Arr::only($data, self::KOLOM_DATA_DIRI),
            'status' => $status,
            'tanggal_keluar' => $status === StatusMurid::Aktif ? null : $baris['tanggal_keluar'],
            'tahun_lulus' => $status === StatusMurid::Lulus ? (int) $data['tahun_lulus'] : null,
            'sekolah_tujuan' => $status === StatusMurid::Pindah ? $data['sekolah_tujuan'] : null,
            'keterangan_status' => $status === StatusMurid::Aktif ? null : $data['keterangan'],
        ];
    }

    /**
     * @param  HasilPeriksa  $hasil
     * @return BarisPratinjau
     */
    private function untukRespons(array $hasil): array
    {
        return ['nomor' => $hasil['nomor'], 'data' => $hasil['data'], 'valid' => $hasil['valid'], 'alasan' => $hasil['alasan']];
    }
}
