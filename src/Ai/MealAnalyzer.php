<?php

namespace App\Ai;

/**
 * The app's AI engine: turns a meal photo and/or description into foods with portions and
 * nutrition values, and suggests meals that fill what's left of the day. Implementations
 * are swappable (KALORIE_ANALYZER): Claude through the host bridge today, the Anthropic API
 * or a local model later.
 */
interface MealAnalyzer
{
    /** @throws AnalysisFailed with a message that can be shown to the user */
    public function analyze(AnalysisRequest $request): MealAnalysis;

    /** @throws AnalysisFailed with a message that can be shown to the user */
    public function suggest(SuggestionRequest $request): MealSuggestions;
}
