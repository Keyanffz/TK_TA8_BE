<?php

namespace App\Providers;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // Bawaan Carbon menulis JSON dalam UTC ("...Z"); kontrak A7 meminta offset +07:00.
        Carbon::serializeUsing(
            fn (CarbonInterface $waktu): string => $waktu->copy()->setTimezone(config('app.timezone'))->toIso8601String(),
        );
    }
}
