<?php

namespace App\Ai;

use App\Http\ApiError;

/** The analysis didn't produce a result; the message is for the user. */
final class AnalysisFailed extends ApiError
{
    public static function unavailable(): self
    {
        return new self(503, 'ai_unavailable', 'Analizator AI jest teraz niedostępny. Spróbuj za chwilę albo dodaj posiłek ręcznie.');
    }

    public static function busy(): self
    {
        return new self(503, 'ai_busy', 'Analizator ma teraz kilka zdjęć naraz. Spróbuj za chwilę.', ['Retry-After' => '10']);
    }

    public static function usageLimit(): self
    {
        return new self(503, 'ai_limit', 'Limit subskrypcji Claude jest chwilowo wyczerpany. Spróbuj później albo dodaj posiłek ręcznie.');
    }

    public static function timeout(): self
    {
        return new self(504, 'ai_timeout', 'Analiza trwała za długo. Spróbuj jeszcze raz.');
    }

    public static function failed(): self
    {
        return new self(502, 'ai_failed', 'Nie udało się przeanalizować posiłku. Spróbuj jeszcze raz.');
    }
}
