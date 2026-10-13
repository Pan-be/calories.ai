<?php

namespace App\Http;

final class Dates
{
    /** A strict Y-m-d date (midnight UTC), or null. */
    public static function day(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
