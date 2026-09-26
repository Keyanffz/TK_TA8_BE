<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MediaController;
use Illuminate\Support\Facades\Route;

Route::pattern('id', '[0-9]+');

Route::get('/health', HealthController::class)->name('health');
Route::get('/media/{token}', MediaController::class)->middleware('signed:relative')->name('media');
