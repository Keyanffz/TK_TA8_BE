<?php

namespace App\Models;

use App\Enums\Hubungan;
use App\Enums\HubunganWali;
use App\Enums\Kewarganegaraan;
use App\Enums\Pekerjaan;
use App\Enums\PendidikanTerakhir;
use App\Enums\Penghasilan;
use App\Enums\StatusOrangTua;
use App\Support\FormulirMurid;
use Database\Factories\OrangTuaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ayah, ibu, atau wali dari formulir data murid, milik satu murid atau satu pendaftaran. Terpisah dari akun Wali
 * Murid: akun adalah orang yang login, baris ini isi formulir. `agama` tidak di-cast, sama seperti agama murid.
 */
#[Fillable([
    'murid_id', 'pendaftaran_id', 'peran', 'status', 'hubungan', 'hubungan_lainnya', 'nama', 'tempat_lahir',
    'tanggal_lahir', 'agama', 'kewarganegaraan', 'pendidikan_terakhir', 'pekerjaan', 'pekerjaan_lainnya',
    'penghasilan', 'alamat_sama_dengan_anak', 'alamat_rumah', 'alamat_kantor', 'no_hp',
])]
class OrangTua extends Model
{
    /** @use HasFactory<OrangTuaFactory> */
    use HasFactory;

    protected $table = 'orang_tua';

    /** Isian formulir per orang, tanpa kolom pemilik dan peran. */
    public const ISIAN = [
        'status', 'hubungan', 'hubungan_lainnya', 'nama', 'tempat_lahir', 'tanggal_lahir', 'agama', 'kewarganegaraan',
        'pendidikan_terakhir', 'pekerjaan', 'pekerjaan_lainnya', 'penghasilan', 'alamat_sama_dengan_anak', 'alamat_rumah',
        'alamat_kantor', 'no_hp',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'peran' => Hubungan::class,
            'status' => StatusOrangTua::class,
            'hubungan' => HubunganWali::class,
            'tanggal_lahir' => 'date',
            'kewarganegaraan' => Kewarganegaraan::class,
            'pendidikan_terakhir' => PendidikanTerakhir::class,
            'pekerjaan' => Pekerjaan::class,
            'penghasilan' => Penghasilan::class,
            'alamat_sama_dengan_anak' => 'boolean',
        ];
    }

    /**
     * Isian dalam bentuk yang dipakai validasi dan pemeriksaan kelengkapan.
     *
     * @return array<string, mixed>
     */
    public function isian(): array
    {
        return array_map(FormulirMurid::polos(...), $this->only(self::ISIAN));
    }
}
