<?php

namespace App\Providers;

use App\Http\Requests\Auth\LoginWaliRequest;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    private const BATAS_LOGIN_PER_MENIT = 5;

    private const BATAS_LOGIN_WALI_PER_MENIT = 5;

    private const BATAS_TAMBAH_ANAK_PER_MENIT = 5;

    private const BATAS_API_PER_MENIT = 120;

    private const BATAS_PENDAFTARAN_PUBLIK_PER_JAM = 3;

    private const BATAS_CEK_STATUS_PENDAFTARAN_PER_MENIT = 10;

    private const PANJANG_MINIMAL_PASSWORD = 8;

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // Bawaan Carbon menulis JSON dalam UTC ("...Z"); kontrak A7 meminta offset +07:00.
        Carbon::serializeUsing(
            fn (CarbonInterface $waktu): string => $waktu->copy()->setTimezone(config('app.timezone'))->toIso8601String(),
        );

        Password::defaults(fn () => Password::min(self::PANJANG_MINIMAL_PASSWORD)->letters()->numbers());

        // Tanpa ini, Resource yang relasinya di-eager load ditulis `allOf: [$ref, { required: [...] }]`, dan
        // openapi-typescript menerjemahkan bagian kedua menjadi `Record<string, never>` sehingga semua field
        // Resource bertipe never di FE. Field relasi tetap terdokumentasi (opsional) di skema Resource.
        Scramble::configure()->withoutEagerLoadAnalysis();

        $this->daftarkanGate();
        $this->daftarkanRateLimiter();
    }

    private function daftarkanGate(): void
    {
        Gate::define('viewApiDocs', fn (?User $user = null): bool => ! $this->app->isProduction());

        Gate::define('kelola-keuangan', fn (User $user): bool => $user->bisaKelolaKeuangan());
    }

    /**
     * Batas B7. Limiter `api` dihitung per user untuk request yang sudah login dan per IP untuk yang belum.
     */
    private function daftarkanRateLimiter(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(self::BATAS_LOGIN_PER_MENIT)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('login-wali', fn (Request $request) => Limit::perMinute(self::BATAS_LOGIN_WALI_PER_MENIT)
            ->by(LoginWaliRequest::normalkanUsername((string) $request->input('username')).'|'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(self::BATAS_API_PER_MENIT)
            ->by($request->user() === null ? 'ip:'.$request->ip() : 'user:'.$request->user()->getAuthIdentifier()));

        RateLimiter::for('pendaftaran-publik', fn (Request $request) => Limit::perHour(self::BATAS_PENDAFTARAN_PUBLIK_PER_JAM)
            ->by((string) $request->ip()));

        // Kode pendaftaran berurutan dan tanggal lahir anak hanya berkisar satu-dua tahun, jadi percobaan dibatasi.
        RateLimiter::for('status-pendaftaran', fn (Request $request) => Limit::perMinute(self::BATAS_CEK_STATUS_PENDAFTARAN_PER_MENIT)
            ->by((string) $request->ip()));

        RateLimiter::for('tambah-anak', fn (Request $request) => Limit::perMinute(self::BATAS_TAMBAH_ANAK_PER_MENIT)
            ->by((string) $request->user()?->getAuthIdentifier()));
    }
}
