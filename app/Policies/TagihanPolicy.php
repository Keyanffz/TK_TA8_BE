<?php

namespace App\Policies;

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Enums\StatusKelasMurid;
use App\Models\Kelas;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pemegang izin lihat keuangan melihat semua tagihan; guru lain hanya tagihan murid kelasnya; wali hanya
 * tagihan anaknya. Tagihan di luar jangkauan dibalas 404 (B4).
 */
class TagihanPolicy
{
    public function view(User $user, Tagihan $tagihan): Response
    {
        return Tagihan::query()->visibleTo($user)->whereKey($tagihan->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Mencatat pembayaran tunai: pemegang izin kelola keuangan untuk tagihan mana pun, dan wali kelas untuk
     * murid yang penempatannya aktif di kelasnya pada tahun ajaran aktif. Guru lain, termasuk guru pendamping,
     * hanya bisa melihat (403 untuk tagihan yang terlihat, 404 untuk lainnya).
     */
    public function bayar(User $user, Tagihan $tagihan): Response
    {
        $lihat = $this->view($user, $tagihan);

        if ($lihat->denied() || $user->punyaIzin(ModulIzin::Keuangan, LevelIzin::Kelola) || $this->waliKelasMurid($user, $tagihan->murid_id)) {
            return $lihat;
        }

        return Response::deny();
    }

    private function waliKelasMurid(User $user, int $muridId): bool
    {
        return Kelas::query()
            ->whereHas('tahunAjaran', fn (Builder $tahunAjaran) => $tahunAjaran->where('is_aktif', true))
            ->whereHas('waliKelas', fn (Builder $guru) => $guru->where('user_id', $user->id))
            ->whereHas('kelasMurid', fn (Builder $penempatan) => $penempatan->where('murid_id', $muridId)->where('status', StatusKelasMurid::Aktif))
            ->exists();
    }
}
