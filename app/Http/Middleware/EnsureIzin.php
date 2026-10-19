<?php

namespace App\Http\Middleware;

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Exceptions\IzinKurangException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemakaian di route: `->middleware('izin:keuangan,kelola')`. Kepala Sekolah selalu lolos; guru butuh izin modul
 * itu di level yang sama atau lebih tinggi; wali murid selalu ditolak. Nama modul atau level yang salah ketik di
 * route memicu error 500 supaya cepat ketahuan.
 */
class EnsureIzin
{
    /**
     * @throws IzinKurangException
     */
    public function handle(Request $request, Closure $next, string $modul, string $level): Response
    {
        $modulIzin = ModulIzin::from($modul);
        $levelIzin = LevelIzin::from($level);
        $user = $request->user();

        if (! $user instanceof User) {
            throw IzinKurangException::untuk($modulIzin, $levelIzin);
        }

        $user->pastikanIzin($modulIzin, $levelIzin);

        return $next($request);
    }
}
