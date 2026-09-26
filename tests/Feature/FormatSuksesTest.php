<?php

use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

it('membungkus list berpaginasi dengan meta current_page, per_page, total, last_page', function () {
    User::factory()->count(3)->create();
    Route::middleware('api')->get('/api/v1/_uji/daftar', fn () => ApiResponse::paginated(
        JsonResource::collection(User::query()->orderBy('id')->paginate(2)),
    ));

    $this->getJson('/api/v1/_uji/daftar?page=2')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Berhasil')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta', ['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
});
