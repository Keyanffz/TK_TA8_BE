<?php

namespace App\Support;

final class Jarak
{
    private const JARI_JARI_BUMI_METER = 6_371_000;

    /**
     * Jarak dua titik koordinat di permukaan bumi dengan rumus Haversine, dalam meter.
     */
    public static function meter(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $selisihLatitude = deg2rad($latitudeB - $latitudeA);
        $selisihLongitude = deg2rad($longitudeB - $longitudeA);

        $a = sin($selisihLatitude / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($selisihLongitude / 2) ** 2;

        return self::JARI_JARI_BUMI_METER * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
