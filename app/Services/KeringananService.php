<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Keringanan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keringanan dipakai saat tagihan dibuat (generate bulanan dan tagihan sekali). Tagihan yang sudah ada
 * tidak dihitung ulang ketika keringanan ditambah, diubah, atau dihapus. Setiap perubahan dicatat di log
 * aktivitas `keringanan`, karena guru berizin kelola keuangan juga bisa mengubahnya.
 */
class KeringananService
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function buat(array $data, User $pembuat): Keringanan
    {
        $keringanan = DB::transaction(function () use ($data, $pembuat): Keringanan {
            $this->pastikanTidakTumpangTindih($data);

            return Keringanan::query()->create([...$data, 'dibuat_oleh' => $pembuat->id]);
        });
        $this->catatLog($keringanan, $pembuat, 'dibuat', 'Menambah');

        return $keringanan;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function perbarui(Keringanan $keringanan, array $data, User $pelaku): Keringanan
    {
        DB::transaction(function () use ($keringanan, $data): void {
            $this->pastikanTidakTumpangTindih($data, $keringanan->id);
            $keringanan->update($data);
        });
        $this->catatLog($keringanan, $pelaku, 'diubah', 'Mengubah');

        return $keringanan;
    }

    public function hapus(Keringanan $keringanan, User $pelaku): void
    {
        $keringanan->delete();
        $this->catatLog($keringanan, $pelaku, 'dihapus', 'Menghapus');
    }

    private function catatLog(Keringanan $keringanan, User $pelaku, string $event, string $kataKerja): void
    {
        $keringanan->loadMissing(['murid', 'jenisTagihan']);

        activity('keringanan')->causedBy($pelaku)->performedOn($keringanan)->event($event)
            ->withProperties([
                'murid_id' => $keringanan->murid_id, 'jenis_tagihan_id' => $keringanan->jenis_tagihan_id,
                'tipe' => $keringanan->tipe->value, 'nilai' => $keringanan->nilai,
            ])
            ->log("{$kataKerja} keringanan {$keringanan->jenisTagihan->nama} untuk {$keringanan->murid->nama_lengkap}");
    }

    /**
     * Satu murid tidak boleh punya dua keringanan untuk jenis tagihan yang sama pada tanggal yang sama,
     * supaya potongan yang dipakai saat membuat tagihan tidak ambigu.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    private function pastikanTidakTumpangTindih(array $data, ?int $kecualiId = null): void
    {
        $mulai = (string) $data['berlaku_mulai'];
        $sampai = $data['berlaku_sampai'] ?? null;

        $bentrok = Keringanan::query()
            ->where('murid_id', $data['murid_id'])
            ->where('jenis_tagihan_id', $data['jenis_tagihan_id'])
            ->when($kecualiId !== null, fn (Builder $query) => $query->whereKeyNot($kecualiId))
            ->where(fn (Builder $query) => $query->whereNull('berlaku_sampai')->orWhereDate('berlaku_sampai', '>=', $mulai))
            ->when($sampai !== null, fn (Builder $query) => $query->whereDate('berlaku_mulai', '<=', (string) $sampai))
            ->lockForUpdate()
            ->first();

        if ($bentrok !== null) {
            $periode = $bentrok->berlaku_mulai->translatedFormat('j F Y').' s.d. '.($bentrok->berlaku_sampai?->translatedFormat('j F Y') ?? 'seterusnya');

            throw new BusinessRuleException("Murid ini sudah punya keringanan untuk jenis tagihan yang sama pada periode {$periode}. Ubah atau hapus keringanan itu terlebih dahulu.");
        }
    }
}
