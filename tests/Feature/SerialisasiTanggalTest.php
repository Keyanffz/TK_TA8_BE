<?php

use Illuminate\Support\Carbon;

it('menulis tanggal di JSON sebagai ISO 8601 dengan offset +07:00', function (Carbon $waktu) {
    expect(json_encode(['waktu' => $waktu]))->toBe('{"waktu":"2026-09-26T14:00:00+07:00"}');
})->with([
    'waktu Jakarta' => fn () => Carbon::parse('2026-09-26 14:00:00', 'Asia/Jakarta'),
    'waktu UTC' => fn () => Carbon::parse('2026-09-26 07:00:00', 'UTC'),
]);
