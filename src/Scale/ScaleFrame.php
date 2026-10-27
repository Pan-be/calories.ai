<?php

namespace App\Scale;

/**
 * The 118-byte frame the 4fizjo B8 scale sends on FFF3 about 15 s after a weighing:
 * 33 CC, length (2 bytes LE), type 01 03 81 01, weight kg×100 (BE) at bytes 10-11,
 * 22 big-endian float32 values from byte 28 (most likely segmental impedances; the
 * mapping isn't known yet; the last value, 43 5F 03 03 in the first real frame, may be
 * something else), checksum = (sum of the other bytes + 1) & 0xFF.
 */
final readonly class ScaleFrame
{
    public const LENGTH = 118;
    private const TYPE = "\x01\x03\x81\x01";
    private const VALUES_OFFSET = 28;
    private const VALUES = 22;

    /** @param list<float> $impedances */
    private function __construct(public string $hex, public float $weightKg, public array $impedances)
    {
    }

    /** Hex as the test page copies it (spaces allowed, any case). */
    public static function parse(string $hex): self
    {
        $hex = strtolower((string) preg_replace('/\s+/', '', $hex));
        if (strlen($hex) !== self::LENGTH * 2 || !ctype_xdigit($hex)) {
            throw new \InvalidArgumentException('Ramka z wagi musi mieć 118 bajtów zapisanych szesnastkowo.');
        }
        $bytes = (string) hex2bin($hex);
        $length = unpack('v', $bytes, 2)[1] ?? 0;
        if (!str_starts_with($bytes, "\x33\xCC") || $length !== self::LENGTH || substr($bytes, 4, 4) !== self::TYPE) {
            throw new \InvalidArgumentException('To nie jest ramka pomiaru z wagi.');
        }
        $sum = array_sum(unpack('C*', substr($bytes, 0, -1)) ?: []);
        if ((($sum + 1) & 0xFF) !== ord($bytes[-1])) {
            throw new \InvalidArgumentException('Ramka z wagi jest uszkodzona (zła suma kontrolna).');
        }
        $impedances = array_values(unpack('G' . self::VALUES, $bytes, self::VALUES_OFFSET) ?: []);
        foreach ($impedances as $value) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Ramka z wagi zawiera niepoprawne wartości.');
            }
        }

        return new self($hex, (unpack('n', $bytes, 10)[1] ?? 0) / 100, $impedances);
    }
}
