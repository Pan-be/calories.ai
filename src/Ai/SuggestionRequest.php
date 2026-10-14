<?php

namespace App\Ai;

/** What the model needs to suggest the next meal: what's left of the day and the user's habits. */
final readonly class SuggestionRequest
{
    /**
     * @param array{kcal:float, protein_g:float, carbs_g:float, fat_g:float} $goals
     * @param array{kcal:float, protein_g:float, carbs_g:float, fat_g:float} $eaten     so far today
     * @param string                                                         $localTime the user's clock, "19:40"
     * @param list<string>                                                   $mealsToday names of today's meals
     * @param list<array{name:string, times:int, kcal:float, protein_g:float, carbs_g:float, fat_g:float}> $frequent
     *        the user's usual meals lately, most frequent first
     * @param string|null                                                    $workout   today's training, or "odpoczynek"
     * @param string|null                                                    $note      what the user has at hand / fancies
     */
    public function __construct(
        public array $goals,
        public array $eaten,
        public string $localTime,
        public array $mealsToday = [],
        public array $frequent = [],
        public ?string $workout = null,
        public ?string $note = null,
    ) {
    }

    /** @return array{kcal:float, protein_g:float, carbs_g:float, fat_g:float} goal minus eaten, never below zero */
    public function remaining(): array
    {
        $out = [];
        foreach ($this->goals as $k => $goal) {
            $out[$k] = round(max(0.0, $goal - ($this->eaten[$k] ?? 0)), 1);
        }
        return $out;
    }
}
