<?php

namespace App\Tests;

/** Real frames from Konrad's 4fizjo B8 scale, for the scale tests. */
final class ScaleFrames
{
    /** The 118-byte FFF3 frame of the 76.80 kg weighing on 2026-10-27 (Bluefy test page). */
    public const REAL = '33cc76000103810173711e00422202010200000000005c050002a000453370004530000043858000452360004521d000451c4000451b0000434e2000450cf000450a2000'
        . '45366000453300004385c0004528b0004526000043757000451ee000451c0000436120004511e000450f0000435f03030094';

    public const REAL_IMPEDANCES = [
        2871.0, 2816.0, 267.0, 2614.0, 2589.0, 2500.0, 2480.0, 206.125, 2255.0, 2210.0, 2918.0,
        2864.0, 267.5, 2699.0, 2656.0, 245.4375, 2542.0, 2496.0, 225.125, 2334.0, 2288.0, 223.0117645263672,
    ];

    /** Replaces the last byte with the checksum of the rest: (sum of the bytes + 1) & 0xFF. */
    public static function withChecksum(string $hex): string
    {
        $bytes = array_values(unpack('C*', (string) hex2bin(substr($hex, 0, -2))) ?: []);

        return substr($hex, 0, -2) . sprintf('%02x', (array_sum($bytes) + 1) & 0xFF);
    }
}
