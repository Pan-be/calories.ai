<?php

namespace App\Controller\Api;

use App\Ai\AnalysisQuota;
use App\Ai\MealAnalyzer;
use App\Ai\SuggestionRequest;
use App\Entity\User;
use App\Http\Input;
use App\Nutrition\Profiles;
use App\Repository\MealRepository;
use App\Repository\WorkoutRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SuggestionController extends AbstractController
{
    /** How far back "the user's usual meals" reach. */
    private const HABIT_DAYS = 30;

    /**
     * {"note"?}: three meals that fill what's left of today, built from the user's usual
     * meals. Nothing is saved; picking one is a POST /api/meals with source "suggestion".
     */
    #[Route('/api/suggestions', methods: ['POST'])]
    public function suggest(
        Request $request,
        #[CurrentUser] User $user,
        MealRepository $meals,
        WorkoutRepository $workouts,
        Profiles $profiles,
        MealAnalyzer $analyzer,
        AnalysisQuota $quota,
    ): JsonResponse {
        $note = Input::json($request)->string('note', 300, false);
        $today = $profiles->today($user);
        $goals = array_map('floatval', $user->getGoals());
        $eaten = $meals->dailyTotals($user, $today, $today)[$today] ?? [];
        $eaten = array_intersect_key($eaten + ['kcal' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0], $goals);
        $from = (new \DateTimeImmutable($today))->modify('-' . self::HABIT_DAYS . ' days')->format('Y-m-d');
        $quota->consume($user);

        $suggestionRequest = new SuggestionRequest(
            goals: $goals,
            eaten: $eaten,
            localTime: (new \DateTimeImmutable('now', $user->zone()))->format('H:i'),
            mealsToday: array_map(fn ($m) => $m->getName(), $meals->forDay($user, $today)),
            frequent: $meals->frequent($user, $from),
            workout: implode("\n", array_map(fn ($w) => $w->summary(), $workouts->forDay($user, $today))) ?: null,
            note: $note,
        );
        $result = $analyzer->suggest($suggestionRequest);

        return $this->json([
            'day' => $today,
            'remaining' => $suggestionRequest->remaining(),
            'suggestions' => $result->suggestions,
            'notes' => $result->notes,
            'ai_model' => $result->model,
        ]);
    }
}
