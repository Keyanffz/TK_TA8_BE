<?php

namespace App\Providers;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    private const BATAS_LOGIN_GOOGLE_PER_MENIT = 10;

    private const BATAS_TAUTKAN_ANAK_PER_MENIT = 5;

    private const PANJANG_MINIMAL_PASSWORD = 8;

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // Bawaan Carbon menulis JSON dalam UTC ("...Z"); kontrak A7 meminta offset +07:00.
        Carbon::serializeUsing(
            fn (CarbonInterface $waktu): string => $waktu->copy()->setTimezone(config('app.timezone'))->toIso8601String(),
        );

        Password::defaults(fn () => Password::min(self::PANJANG_MINIMAL_PASSWORD)->letters()->numbers());

        $this->daftarkanGate();
        $this->daftarkanRateLimiter();
    }

    private function daftarkanGate(): void
    {
        Gate::define('viewApiDocs', fn (?User $user = null): bool => ! $this->app->isProduction());

        Gate::define('kelola-keuangan', fn (User $user): bool => $user->bisaKelolaKeuangan());
    }

    /**
     * Batas B7. Limiter API umum (120/menit per user) dipasang di Fase 8.
     */
    private function daftarkanRateLimiter(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(self::BATAS_LOGIN_PER_MENIT)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('login-google', fn (Request $request) => Limit::perMinute(self::BATAS_LOGIN_GOOGLE_PER_MENIT)
            ->by((string) $request->ip()));

        RateLimiter::for('tautkan-anak', fn (Request $request) => Limit::perMinute(self::BATAS_TAUTKAN_ANAK_PER_MENIT)
            ->by((string) $request->user()?->getAuthIdentifier()));
    }
}
