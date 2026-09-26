<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Keringanan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keringanan dipakai saat tagihan dibuat (generate bulanan dan tagihan sekali). Tagihan yang sudah ada
 * tidak dihitung ulang ketika keringanan ditambah, diubah, atau dihapus.
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
        return DB::transaction(function () use ($data, $pembuat): Keringanan {
            $this->pastikanTidakTumpangTindih($data);

            return Keringanan::query()->create([...$data, 'dibuat_oleh' => $pembuat->id]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function perbarui(Keringanan $keringanan, array $data): Keringanan
    {
        return DB::transaction(function () use ($keringanan, $data): Keringanan {
            $this->pastikanTidakTumpangTindih($data, $keringanan->id);
            $keringanan->update($data);

            return $keringanan;
        });
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
