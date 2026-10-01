<?php

namespace App\Services;

use App\Enums\NadaInfo;
use App\Exceptions\BusinessRuleException;
use App\Models\Pengaturan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Support\AturanAbsensi;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Stevebauman\Purify\Facades\Purify;

/**
 * Pengaturan sekolah per kunci (A4 "Kunci pengaturan", B6.12). Semua kunci dibaca sekaligus dan disimpan di
 * cache; cache dibuang setiap kali baris pengaturan tersimpan (lihat `Pengaturan::booted()`). Aturan validasi
 * per kunci ada di `aturan()`, satu-satunya tempat daftar kunci yang boleh diubah.
 */
class PengaturanService
{
    public const KUNCI_CACHE = 'pengaturan.semua';

    public const FOLDER_GAMBAR = 'pengaturan';

    /** Kunci yang isinya HTML dari editor dan harus disanitasi sebelum disimpan. */
    private const KUNCI_HTML = ['profil.sejarah', 'profil.sambutan_kepsek', 'ppdb.info'];

    /** Kunci angka disimpan sebagai integer walau dikirim sebagai string dari form multipart. */
    private const KUNCI_ANGKA = [
        'keuangan.tanggal_jatuh_tempo', 'keuangan.hari_pengingat', 'ppdb.tahun_ajaran_id', 'ppdb.kuota',
        'absensi.radius_meter', 'absensi.batas_akurasi_meter', 'absensi.masa_simpan_foto_bulan',
    ];

    private const MAKSIMAL_TANGGAL_LIBUR = 366;

    private const MAKSIMAL_ITEM_DAFTAR = 20;

    /**
     * Field item objek di pengaturan landing. Field opsional yang tidak dikirim saat `PUT /pengaturan` tetap
     * muncul di respons sebagai null, supaya bentuk respons selalu sama.
     */
    private const FIELD_ITEM = [
        'landing.hero' => ['judul', 'subjudul', 'gambar', 'cta_teks'],
        'landing.program' => ['judul', 'deskripsi', 'ikon'],
        'landing.fasilitas' => ['nama', 'deskripsi', 'gambar'],
        'landing.keunggulan' => ['judul', 'deskripsi', 'ikon'],
    ];

    public function nilai(string $kunci, mixed $bawaan = null): mixed
    {
        return $this->semua()[$kunci] ?? $bawaan;
    }

    /**
     * @return list<array{bank: string, nomor: string, atas_nama: string}>
     */
    public function rekeningSekolah(): array
    {
        $rekening = $this->nilai('keuangan.rekening', []);

        return is_array($rekening) ? array_values($rekening) : [];
    }

    /**
     * Pengaturan satu grup (atau semua grup kalau null) sebagai objek datar berkunci lengkap, dengan pasangan
     * `*_url` untuk setiap field gambar (A7 CMS & pengaturan).
     *
     * @param  list<string>|null  $grup
     * @return array<string, mixed>
     */
    public function untukRespons(?array $grup = null): array
    {
        $hasil = [];

        foreach ($this->semua() as $kunci => $nilai) {
            if ($grup !== null && ! in_array(strstr($kunci, '.', true), $grup, true)) {
                continue;
            }

            $hasil[$kunci] = $this->denganUrlGambar($kunci, $this->lengkapiFieldItem($kunci, $nilai));

            if ($kunci === 'profil.logo') {
                $hasil['profil.logo_url'] = $this->urlGambar($nilai);
            }
        }

        return $hasil;
    }

    /**
     * Menyimpan sebagian kunci. Field `*_url` diabaikan, HTML disanitasi, dan gambar yang tidak dipakai lagi
     * dihapus dari disk.
     *
     * @param  array<string, mixed>  $items
     *
     * @throws ValidationException
     * @throws BusinessRuleException
     */
    public function simpan(array $items, User $pelaku): void
    {
        unset($items['profil.logo_url']);
        $items = $this->buangUrlGambar($items);
        $this->validasi($items);
        $items = $this->rapikan($items);

        $sesudah = [...$this->semua(), ...$items];
        $buka = $sesudah['ppdb.tanggal_buka'] ?? null;
        $tutup = $sesudah['ppdb.tanggal_tutup'] ?? null;
        if (is_string($buka) && is_string($tutup) && $tutup < $buka) {
            throw ValidationException::withMessages(['ppdb.tanggal_tutup' => 'Tanggal tutup PPDB tidak boleh sebelum tanggal buka.']);
        }
        $this->pastikanJamAbsensiUrut($sesudah);
        if (($sesudah['ppdb.dibuka'] ?? false) === true && ! $this->tahunAjaranPpdbAda($sesudah['ppdb.tahun_ajaran_id'] ?? null)) {
            throw new BusinessRuleException('PPDB tidak bisa dibuka sebelum tahun ajaran tujuan PPDB dipilih.');
        }

        $gambarLama = $this->semuaGambar($this->semua());

        DB::transaction(function () use ($items): void {
            foreach ($items as $kunci => $nilai) {
                Pengaturan::query()->updateOrCreate(
                    ['kunci' => $kunci],
                    ['nilai' => $nilai, 'grup' => strstr($kunci, '.', true)],
                );
            }
        });

        foreach (array_diff($gambarLama, $this->semuaGambar($this->semua())) as $path) {
            Storage::disk(MediaService::DISK_PUBLIK)->delete($path);
        }

        activity('pengaturan')->causedBy($pelaku)->event('diubah')
            ->withProperties(['kunci' => array_keys($items)])
            ->log('Mengubah pengaturan: '.implode(', ', array_keys($items)));
    }

    /**
     * Banner info sekolah untuk beranda wali murid, atau null kalau tidak aktif atau sudah lewat
     * `berlaku_sampai` (tanggal itu sendiri masih tampil).
     *
     * @return array{judul: string, isi: string, nada: NadaInfo, berlaku_sampai: string|null}|null
     */
    public function infoWali(): ?array
    {
        $info = $this->nilai('beranda.info_wali');

        if (! is_array($info) || ($info['aktif'] ?? false) !== true) {
            return null;
        }

        $berlakuSampai = $info['berlaku_sampai'] ?? null;
        if (is_string($berlakuSampai) && $berlakuSampai < today()->toDateString()) {
            return null;
        }

        return [
            'judul' => (string) ($info['judul'] ?? ''),
            'isi' => (string) ($info['isi'] ?? ''),
            /** @var NadaInfo */
            'nada' => NadaInfo::tryFrom((string) ($info['nada'] ?? '')) ?? NadaInfo::Info,
            'berlaku_sampai' => is_string($berlakuSampai) ? $berlakuSampai : null,
        ];
    }

    /**
     * Kop dokumen PDF (kwitansi, rapor) dari pengaturan profil sekolah. `logo` berupa path file lokal supaya
     * bisa dibaca dompdf tanpa akses jaringan; null kalau belum diunggah.
     *
     * @return array{nama: string, alamat: string, telepon: string, email: string, logo: string|null}
     */
    public function kopSekolah(): array
    {
        $logo = $this->nilai('profil.logo');
        $diskPublik = Storage::disk(MediaService::DISK_PUBLIK);

        return [
            'nama' => (string) $this->nilai('profil.nama_sekolah', ''),
            'alamat' => (string) $this->nilai('profil.alamat', ''),
            'telepon' => (string) $this->nilai('profil.telepon', ''),
            'email' => (string) $this->nilai('profil.email', ''),
            'logo' => is_string($logo) && $diskPublik->exists($logo) ? $diskPublik->path($logo) : null,
        ];
    }

    /**
     * Aturan validasi per kunci. Gambar berupa path hasil `POST /pengaturan/upload`.
     *
     * @return array<string, mixed>
     */
    public function aturan(): array
    {
        $gambar = ['nullable', 'string', $this->gambarAda(...)];
        $ikon = ['required', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/'];
        $daftar = ['array', 'list', 'max:'.self::MAKSIMAL_ITEM_DAFTAR];

        return [
            'profil.nama_sekolah' => ['required', 'string', 'max:255'],
            'profil.npsn' => ['nullable', 'string', 'regex:/^[0-9]{8}$/'],
            'profil.alamat' => ['nullable', 'string', 'max:500'],
            'profil.telepon' => ['nullable', 'string', 'max:30'],
            'profil.email' => ['nullable', 'email', 'max:255'],
            'profil.maps_embed_url' => ['nullable', 'url:https', 'max:2000'],
            'profil.logo' => $gambar,
            'profil.visi' => ['nullable', 'string', 'max:1000'],
            'profil.misi' => $daftar,
            'profil.misi.*' => ['required', 'string', 'max:500'],
            'profil.sejarah' => ['nullable', 'string', 'max:50000'],
            'profil.sambutan_kepsek' => ['nullable', 'string', 'max:50000'],
            'landing.hero' => ['array:judul,subjudul,gambar,cta_teks'],
            'landing.hero.judul' => ['required', 'string', 'max:255'],
            'landing.hero.subjudul' => ['nullable', 'string', 'max:500'],
            'landing.hero.gambar' => $gambar,
            'landing.hero.cta_teks' => ['nullable', 'string', 'max:50'],
            'landing.program' => $daftar,
            'landing.program.*' => ['array:judul,deskripsi,ikon'],
            'landing.program.*.judul' => ['required', 'string', 'max:255'],
            'landing.program.*.deskripsi' => ['nullable', 'string', 'max:1000'],
            'landing.program.*.ikon' => $ikon,
            'landing.fasilitas' => $daftar,
            'landing.fasilitas.*' => ['array:nama,deskripsi,gambar'],
            'landing.fasilitas.*.nama' => ['required', 'string', 'max:255'],
            'landing.fasilitas.*.deskripsi' => ['nullable', 'string', 'max:1000'],
            'landing.fasilitas.*.gambar' => $gambar,
            'landing.keunggulan' => $daftar,
            'landing.keunggulan.*' => ['array:judul,deskripsi,ikon'],
            'landing.keunggulan.*.judul' => ['required', 'string', 'max:255'],
            'landing.keunggulan.*.deskripsi' => ['nullable', 'string', 'max:1000'],
            'landing.keunggulan.*.ikon' => $ikon,
            'keuangan.rekening' => ['array', 'list', 'max:5'],
            'keuangan.rekening.*' => ['array:bank,nomor,atas_nama'],
            'keuangan.rekening.*.bank' => ['required', 'string', 'max:50'],
            'keuangan.rekening.*.nomor' => ['required', 'string', 'regex:/^[0-9 .-]{5,30}$/'],
            'keuangan.rekening.*.atas_nama' => ['required', 'string', 'max:100'],
            'keuangan.tanggal_jatuh_tempo' => ['integer', 'between:1,28'],
            'keuangan.hari_pengingat' => ['integer', 'between:1,14'],
            'ppdb.dibuka' => ['boolean:strict'],
            'ppdb.tanggal_buka' => ['nullable', 'date_format:Y-m-d'],
            'ppdb.tanggal_tutup' => ['nullable', 'date_format:Y-m-d'],
            'ppdb.tahun_ajaran_id' => ['nullable', 'integer', Rule::exists('tahun_ajaran', 'id')],
            'ppdb.kuota' => ['integer', 'between:0,1000'],
            'ppdb.info' => ['nullable', 'string', 'max:50000'],
            'beranda.info_wali' => ['array:aktif,judul,isi,nada,berlaku_sampai'],
            'beranda.info_wali.aktif' => ['required', 'boolean:strict'],
            'beranda.info_wali.judul' => ['nullable', 'required_if_accepted:beranda.info_wali.aktif', 'string', 'max:100'],
            'beranda.info_wali.isi' => ['nullable', 'required_if_accepted:beranda.info_wali.aktif', 'string', 'max:1000'],
            'beranda.info_wali.nada' => ['required', Rule::enum(NadaInfo::class)],
            'beranda.info_wali.berlaku_sampai' => ['nullable', 'date_format:Y-m-d'],
            'absensi.lokasi' => ['nullable', 'array:latitude,longitude', 'required_array_keys:latitude,longitude'],
            'absensi.lokasi.latitude' => ['numeric', 'between:-90,90'],
            'absensi.lokasi.longitude' => ['numeric', 'between:-180,180'],
            'absensi.radius_meter' => ['integer', 'between:10,5000'],
            'absensi.batas_akurasi_meter' => ['integer', 'between:5,1000'],
            'absensi.jam_masuk' => ['array:buka,batas_terlambat,tutup'],
            'absensi.jam_masuk.buka' => ['required', 'date_format:H:i'],
            'absensi.jam_masuk.batas_terlambat' => ['required', 'date_format:H:i'],
            'absensi.jam_masuk.tutup' => ['required', 'date_format:H:i'],
            'absensi.jam_pulang' => ['array:buka,tutup'],
            'absensi.jam_pulang.buka' => ['required', 'date_format:H:i'],
            'absensi.jam_pulang.tutup' => ['required', 'date_format:H:i'],
            'absensi.hari_kerja' => ['array', 'list', 'min:1', 'max:7'],
            'absensi.hari_kerja.*' => ['integer', 'between:1,7', 'distinct'],
            'absensi.tanggal_libur' => ['array', 'list', 'max:'.self::MAKSIMAL_TANGGAL_LIBUR],
            'absensi.tanggal_libur.*' => ['date_format:Y-m-d', 'distinct'],
            'absensi.masa_simpan_foto_bulan' => ['integer', 'between:1,60'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function semua(): array
    {
        return Cache::rememberForever(self::KUNCI_CACHE, fn (): array => Pengaturan::query()
            ->orderBy('kunci')
            ->pluck('nilai', 'kunci')
            ->all());
    }

    /**
     * @param  array<string, mixed>  $items
     *
     * @throws ValidationException
     */
    private function validasi(array $items): void
    {
        $aturan = $this->aturan();
        $dikenal = array_filter(array_keys($aturan), fn (string $kunci): bool => substr_count($kunci, '.') === 1);
        $asing = array_diff(array_keys($items), $dikenal);

        if ($asing !== []) {
            throw ValidationException::withMessages(['items' => 'Kunci pengaturan tidak dikenal: '.implode(', ', $asing).'.']);
        }

        // Validator memakai titik sebagai pemisah bertingkat, sedangkan kunci pengaturan juga bertitik,
        // jadi setiap kunci dipecah menjadi array bertingkat dan aturannya diambil sesuai kunci yang dikirim.
        $data = [];
        $aturanDipakai = [];
        foreach ($items as $kunci => $nilai) {
            data_set($data, $kunci, $nilai);
            foreach ($aturan as $pola => $satu) {
                if ($pola === $kunci || str_starts_with($pola, $kunci.'.')) {
                    $aturanDipakai[$pola] = $satu;
                }
            }
        }

        Validator::make($data, $aturanDipakai, [], $this->namaAtribut(array_keys($aturanDipakai)))->validate();
    }

    /**
     * Nama field di pesan validasi: bagian terakhir kunci tanpa garis bawah, misalnya "nama sekolah" untuk
     * `profil.nama_sekolah` dan "judul" untuk `landing.program.*.judul`.
     *
     * @param  list<string>  $pola
     * @return array<string, string>
     */
    private function namaAtribut(array $pola): array
    {
        return collect($pola)->mapWithKeys(fn (string $satu): array => [
            $satu => str_replace('_', ' ', Str::afterLast(Str::beforeLast($satu, '.*'), '.')),
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function rapikan(array $items): array
    {
        foreach ($items as $kunci => $nilai) {
            if (in_array($kunci, self::KUNCI_HTML, true) && is_string($nilai)) {
                $items[$kunci] = Purify::clean($nilai);
            } elseif (in_array($kunci, self::KUNCI_ANGKA, true) && is_numeric($nilai)) {
                $items[$kunci] = (int) $nilai;
            } elseif ($kunci === 'absensi.lokasi' && is_array($nilai)) {
                $items[$kunci] = ['latitude' => (float) $nilai['latitude'], 'longitude' => (float) $nilai['longitude']];
            } elseif ($kunci === 'absensi.hari_kerja' && is_array($nilai)) {
                $items[$kunci] = collect($nilai)->map(fn (mixed $hari): int => (int) $hari)->sort()->values()->all();
            } elseif ($kunci === 'absensi.tanggal_libur' && is_array($nilai)) {
                $items[$kunci] = collect($nilai)->sort()->values()->all();
            }
        }

        return $items;
    }

    /**
     * Jam absensi dibandingkan sebagai teks `HH:MM`. Diperiksa terhadap gabungan nilai tersimpan dan nilai
     * baru, karena jam masuk dan jam pulang bisa dikirim terpisah.
     *
     * @param  array<string, mixed>  $pengaturan
     *
     * @throws ValidationException
     */
    private function pastikanJamAbsensiUrut(array $pengaturan): void
    {
        $masuk = $pengaturan['absensi.jam_masuk'] ?? AturanAbsensi::BAWAAN['absensi.jam_masuk'];
        $pulang = $pengaturan['absensi.jam_pulang'] ?? AturanAbsensi::BAWAAN['absensi.jam_pulang'];

        if ($masuk['batas_terlambat'] < $masuk['buka'] || $masuk['tutup'] < $masuk['batas_terlambat'] || $masuk['tutup'] <= $masuk['buka']) {
            throw ValidationException::withMessages(['absensi.jam_masuk' => 'Jam masuk harus berurutan: jam buka, batas terlambat, lalu jam tutup.']);
        }
        if ($pulang['tutup'] <= $pulang['buka']) {
            throw ValidationException::withMessages(['absensi.jam_pulang' => 'Jam tutup absen pulang harus setelah jam bukanya.']);
        }
    }

    private function tahunAjaranPpdbAda(mixed $tahunAjaranId): bool
    {
        return is_numeric($tahunAjaranId) && TahunAjaran::query()->whereKey((int) $tahunAjaranId)->exists();
    }

    private function gambarAda(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (! str_starts_with($value, self::FOLDER_GAMBAR.'/') || ! Storage::disk(MediaService::DISK_PUBLIK)->exists($value))) {
            $fail('Gambar tidak ditemukan. Unggah gambar lewat POST /pengaturan/upload lalu kirim path-nya.');
        }
    }

    private function urlGambar(mixed $path): ?string
    {
        return is_string($path) ? app(MediaService::class)->urlPublik($path) : null;
    }

    private function lengkapiFieldItem(string $kunci, mixed $nilai): mixed
    {
        if (! isset(self::FIELD_ITEM[$kunci]) || ! is_array($nilai)) {
            return $nilai;
        }

        $kosong = array_fill_keys(self::FIELD_ITEM[$kunci], null);
        $lengkapi = fn (mixed $item): mixed => is_array($item) ? [...$kosong, ...$item] : $item;

        return $kunci === 'landing.hero' ? $lengkapi($nilai) : array_map($lengkapi, $nilai);
    }

    /**
     * `landing.hero.gambar` dan `landing.fasilitas[].gambar` mendapat pasangan `gambar_url`.
     */
    private function denganUrlGambar(string $kunci, mixed $nilai): mixed
    {
        return match ($kunci) {
            'landing.hero' => is_array($nilai) ? [...$nilai, 'gambar_url' => $this->urlGambar($nilai['gambar'] ?? null)] : $nilai,
            'landing.fasilitas' => is_array($nilai)
                ? array_map(fn (mixed $satu): mixed => is_array($satu) ? [...$satu, 'gambar_url' => $this->urlGambar($satu['gambar'] ?? null)] : $satu, $nilai)
                : $nilai,
            default => $nilai,
        };
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function buangUrlGambar(array $items): array
    {
        if (is_array($items['landing.hero'] ?? null)) {
            unset($items['landing.hero']['gambar_url']);
        }
        if (is_array($items['landing.fasilitas'] ?? null)) {
            $items['landing.fasilitas'] = array_map(function (mixed $satu): mixed {
                if (is_array($satu)) {
                    unset($satu['gambar_url']);
                }

                return $satu;
            }, $items['landing.fasilitas']);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $pengaturan
     * @return list<string>
     */
    private function semuaGambar(array $pengaturan): array
    {
        $hero = $pengaturan['landing.hero'] ?? null;
        $fasilitas = $pengaturan['landing.fasilitas'] ?? null;

        return array_values(array_filter([
            $pengaturan['profil.logo'] ?? null,
            is_array($hero) ? ($hero['gambar'] ?? null) : null,
            ...(is_array($fasilitas) ? array_map(fn (mixed $satu): mixed => is_array($satu) ? ($satu['gambar'] ?? null) : null, $fasilitas) : []),
        ], is_string(...)));
    }
}
