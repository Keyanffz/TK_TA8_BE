<?php

namespace App\Services;

use App\Enums\Imunisasi;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Http\Requests\Murid\SimpanMuridRequest;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\OrangTua;
use App\Models\User;
use App\Support\FormulirMurid;
use App\Support\KelengkapanFormulir;
use App\Support\KolomImportMurid;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Excel as JenisFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;

/**
 * Import murid dari file Excel/CSV hasil template (A2.14). Pratinjau hanya memeriksa; simpan memeriksa ulang (data
 * bisa berubah sejak pratinjau) lalu menyimpan semua baris valid dalam satu transaksi. Murid disimpan tanpa NIS
 * seperti `POST /murid`; akun wali murid aktif dibuat saat Kepala Sekolah mengisi NIS. Murid aktif bisa langsung
 * ditempatkan di kelas tahun ajaran aktif; murid lulus, pindah, dan keluar tidak ikut generate tagihan. Duplikat
 * dideteksi lewat NISN, atau lewat nama lengkap + tanggal lahir untuk baris tanpa NISN; NIK juga tidak boleh dobel.
 * Kolom formulir data murid (`KolomImportMurid`) opsional, sehingga data yang belum lengkap tetap bisa diimpor; murid
 * itu tertandai belum lengkap. Kolom pilihan menerima label atau kode.
 *
 * @phpstan-type IsiBaris array<string, string|null>
 * @phpstan-type BarisPratinjau array{nomor: int, data: IsiBaris, valid: bool, alasan: list<string>}
 * @phpstan-type HasilPeriksa array{nomor: int, data: IsiBaris, valid: bool, alasan: list<string>, kelas: Kelas|null, tanggal_keluar: string|null}
 */
class ImportMuridService
{
    public const MAKSIMAL_BARIS = 500;

    private const KOLOM_DATA_DIRI = [
        'nisn', 'nik', 'nama_lengkap', 'nama_panggilan', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'agama', 'alamat',
        'anak_ke', 'catatan_khusus', 'tanggal_masuk',
    ];

    private const PESAN = [
        Enum::class => ':Attribute tidak ada di daftar pilihan. Lihat sheet Petunjuk.',
        'jenis_kelamin.'.Enum::class => 'Jenis kelamin diisi L atau P.',
        'status.'.Enum::class => 'Status diisi aktif, lulus, pindah, atau keluar.',
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
     * @return array{dibuat: int, gagal: list<BarisPratinjau>}
     *
     * @throws ValidationException
     * @throws BusinessRuleException
     */
    public function simpan(UploadedFile $file, User $pelaku): array
    {
        $hasil = $this->periksa($this->baca($file));
        $valid = array_values(array_filter($hasil, fn (array $satu): bool => $satu['valid']));
        $gagal = array_values(array_map($this->untukRespons(...), array_filter($hasil, fn (array $satu): bool => ! $satu['valid'])));

        try {
            if ($valid !== []) {
                DB::transaction(fn () => $this->simpanBaris($valid));
            }
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('Ada NISN atau NIK yang baru saja dipakai data lain saat import berjalan, jadi tidak ada murid yang disimpan. Unggah ulang file untuk memeriksa datanya lagi.');
        }

        if ($valid !== []) {
            activity('murid')->causedBy($pelaku)->event('diimpor')
                ->withProperties(['nama_file' => $file->getClientOriginalName(), 'dibuat' => count($valid), 'gagal' => count($gagal)])
                ->log('Mengimpor '.count($valid)." murid dari {$file->getClientOriginalName()}");
        }

        return ['dibuat' => count($valid), 'gagal' => $gagal];
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
        $kurang = array_diff(KolomImportMurid::WAJIB, $judul);

        if ($kurang !== []) {
            throw ValidationException::withMessages(['file' => 'Kolom wajib tidak ada di file: '.implode(', ', $kurang).'. Pakai template dari tombol Unduh Template.']);
        }

        $baris = [];
        foreach ($sel as $indeks => $isiBaris) {
            $data = [];
            foreach (array_keys(KolomImportMurid::petunjuk()) as $kolom) {
                $posisi = array_search($kolom, $judul, true);
                $data[$kolom] = $posisi === false ? null : $this->teks($isiBaris[$posisi] ?? null, in_array($kolom, KolomImportMurid::tanggal(), true));
            }

            if (array_filter($data, fn (?string $nilai): bool => $nilai !== null) !== []) {
                $data['status'] ??= StatusMurid::Aktif->value;

                foreach (KolomImportMurid::pilihan() as $kolom => $enum) {
                    $data[$kolom] = $data[$kolom] === null ? null : KolomImportMurid::kode($enum, $data[$kolom]);
                }

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
        $nikTerdaftar = Murid::query()->whereIn('nik', array_filter(array_column($isi, 'nik')))->pluck('nama_lengkap', 'nik')->all();
        $nikSebelumnya = [];
        $namaTerdaftar = Murid::query()->whereIn('nama_lengkap', array_filter(array_column($isi, 'nama_lengkap')))->get(['nama_lengkap', 'tanggal_lahir'])
            ->mapWithKeys(fn (Murid $murid): array => [$this->kunciNama($murid->nama_lengkap, $murid->tanggal_lahir->toDateString()) => true]);
        $barisSebelumnya = [];
        $hasil = [];

        foreach ($baris as ['nomor' => $nomor, 'data' => $data]) {
            $alasan = $this->alasanIsian($data);
            $kunci = $data['nisn'] !== null ? 'nisn:'.$data['nisn'] : 'nama:'.$this->kunciNama($data['nama_lengkap'], $data['tanggal_lahir']);
            $alasan = [...$alasan, ...$this->alasanGanda($data, $barisSebelumnya[$kunci] ?? null, $nisnTerdaftar->all(), $namaTerdaftar->all())];
            $alasan = [...$alasan, ...$this->alasanNik($data['nik'], $nikTerdaftar, $nikSebelumnya[$data['nik'] ?? ''] ?? null)];
            $barisSebelumnya[$kunci] ??= $nomor;

            if ($data['nik'] !== null) {
                $nikSebelumnya[$data['nik']] ??= $nomor;
            }

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
        $isian = [...$data, ...$this->isianFormulir($data)];

        $alasan = Validator::make($isian, [
            ...SimpanMuridRequest::aturanDataDiri(),
            ...FormulirMurid::aturanAnak(),
            ...FormulirMurid::aturanOrangTua(),
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
        ], self::PESAN, FormulirMurid::atribut())->errors()->all();

        return array_values([...$alasan, ...KelengkapanFormulir::masalahKontakUtama($isian, Arr::only($isian, FormulirMurid::PERAN))]);
    }

    /**
     * Kolom baris yang datar (`ayah_nama`) menjadi isian formulir seperti request `POST /murid` (`ayah.nama`).
     * Imunisasi ditulis dipisah koma; alamat rumah "sama" berarti sama dengan alamat anak.
     *
     * @param  IsiBaris  $data
     * @return array<string, mixed>
     */
    private function isianFormulir(array $data): array
    {
        $isian = Arr::only($data, [...self::KOLOM_DATA_DIRI, ...FormulirMurid::KOLOM_ANAK]);
        $isian['imunisasi'] = $data['imunisasi'] === null ? null : array_map(
            fn (string $satu): string => KolomImportMurid::kode(Imunisasi::class, $satu),
            array_values(array_filter(array_map(trim(...), explode(',', $data['imunisasi'])), fn (string $satu): bool => $satu !== '')),
        );

        foreach (FormulirMurid::PERAN as $peran) {
            $orang = [];

            foreach (OrangTua::ISIAN as $kolom) {
                if (array_key_exists("{$peran}_{$kolom}", $data)) {
                    $orang[$kolom] = $data["{$peran}_{$kolom}"];
                }
            }
            if (Str::lower((string) ($orang['alamat_rumah'] ?? '')) === 'sama') {
                $orang = [...$orang, 'alamat_rumah' => null, 'alamat_sama_dengan_anak' => true];
            }

            $isian[$peran] = array_filter($orang, fn (mixed $nilai): bool => $nilai !== null) === [] ? null : $orang;
        }

        return $isian;
    }

    /**
     * @param  array<string, string>  $nikTerdaftar  nama murid per NIK
     * @return list<string>
     */
    private function alasanNik(?string $nik, array $nikTerdaftar, ?int $barisSebelumnya): array
    {
        return match (true) {
            $nik === null => [],
            isset($nikTerdaftar[$nik]) => ["NIK {$nik} sudah dipakai {$nikTerdaftar[$nik]}."],
            $barisSebelumnya !== null => ["NIK {$nik} sama dengan baris {$barisSebelumnya}."],
            default => [],
        };
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
     * @param  list<HasilPeriksa>  $valid
     *
     * @throws BusinessRuleException
     */
    private function simpanBaris(array $valid): void
    {
        $penempatan = [];

        foreach ($valid as $satu) {
            $murid = $this->muridService->buat($this->dataMurid($satu), null);

            if ($satu['kelas'] !== null) {
                $penempatan[$satu['kelas']->id][] = $murid->id;
            }
        }

        foreach ($penempatan as $kelasId => $muridIds) {
            $this->kelasService->tempatkanMurid(Kelas::query()->findOrFail($kelasId), $muridIds);
        }
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
            ...$this->isianFormulir($data),
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
