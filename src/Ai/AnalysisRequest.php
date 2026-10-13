<?php

namespace App\Ai;

final readonly class AnalysisRequest
{
    /**
     * @param string|null $photoJpeg   the meal photo (JPEG bytes), if any
     * @param string|null $description what the user wrote about the meal
     * @param array|null  $previous    an earlier analysis to correct: {name, items}
     * @param string|null $correction  what the user says is wrong with $previous
     */
    public function __construct(
        public ?string $photoJpeg = null,
        public ?string $description = null,
        public ?array $previous = null,
        public ?string $correction = null,
    ) {
    }
}
