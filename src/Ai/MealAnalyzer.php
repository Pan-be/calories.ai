<?php

namespace App\Ai;

/**
 * Turns a meal photo and/or description into foods with portions and nutrition values.
 * Implementations are swappable (KALORIE_ANALYZER): Claude through the host bridge today,
 * the Anthropic API or a local vision model later.
 */
interface MealAnalyzer
{
    /** @throws AnalysisFailed with a message that can be shown to the user */
    public function analyze(AnalysisRequest $request): MealAnalysis;
}
