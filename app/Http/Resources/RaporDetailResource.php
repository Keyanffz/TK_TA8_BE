<?php

namespace App\Http\Resources;

use App\Models\Rapor;
use App\Models\RaporDetail;
use App\Services\MediaService;
use Illuminate\Http\Request;

/**
 * Detail rapor: bentuk daftar ditambah deskripsi dan foto tiap elemen (butuh relasi `detail.elemenPenilaian`).
 * Rapor hanya dikirim ke pengguna yang lolos `Rapor::visibleTo`, jadi `foto_url` aman dibuat di sini.
 *
 * @mixin Rapor
 */
class RaporDetailResource extends RaporResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'detail' => $this->daftarDetail(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function daftarDetail(): array
    {
        $media = app(MediaService::class);

        return $this->detail
            ->sortBy(fn (RaporDetail $detail): int => $detail->elemenPenilaian->urutan)
            ->map(fn (RaporDetail $detail): array => [
                'id' => $detail->id,
                'elemen' => [
                    'id' => $detail->elemenPenilaian->id,
                    'kode' => $detail->elemenPenilaian->kode,
                    'nama' => $detail->elemenPenilaian->nama,
                ],
                'deskripsi' => $detail->deskripsi,
                /** @var string|null */
                'foto_url' => $media->urlPrivat($detail->foto_path),
            ])
            ->values()
            ->all();
    }
}
