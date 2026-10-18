<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ApiError;
use App\Http\Dates;
use App\Nutrition\Presenter;
use App\Repository\MealRepository;
use App\Repository\WeightEntryRepository;
use App\Repository\WorkoutRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class DayController extends AbstractController
{
    public function __construct(private MealRepository $meals, private Presenter $presenter)
    {
    }

    /** One day: goals, what's eaten so far, what's left, the meals and the day's training. */
    #[Route('/api/days/{day}', methods: ['GET'])]
    public function day(string $day, #[CurrentUser] User $user, WeightEntryRepository $weights, WorkoutRepository $workouts): JsonResponse
    {
        if (Dates::day($day) === null) {
            throw ApiError::invalid('Data musi mieć postać RRRR-MM-DD.');
        }
        $meals = $this->meals->forDay($user, $day);
        $totals = ['kcal' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0];
        foreach ($meals as $meal) {
            foreach ($meal->totals() as $k => $v) {
                $totals[$k] += $v;
            }
        }
        $goals = $user->getGoals();
        $remaining = [];
        foreach ($totals as $k => $v) {
            $remaining[$k] = round($goals[$k] - $v, 1);
        }

        return $this->json([
            'day' => $day,
            'goals' => $goals,
            'totals' => Presenter::round($totals),
            'remaining' => $remaining,
            'weight_kg' => $weights->findOneBy(['user' => $user, 'day' => $day])?->getWeightKg(),
            'workouts' => array_map(fn ($w) => $w->toArray(), $workouts->forDay($user, $day)),
            'meals' => array_map($this->presenter->meal(...), $meals),
        ]);
    }

    /** ?days=7&end=YYYY-MM-DD: calories and macros per day, oldest first, days without meals included. */
    #[Route('/api/summary', methods: ['GET'])]
    public function summary(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $days = max(1, min(90, $request->query->getInt('days', 7)));
        $end = Dates::day((string) $request->query->get('end', ''))
            ?? new \DateTimeImmutable((new \DateTimeImmutable('now', $user->zone()))->format('Y-m-d'), new \DateTimeZone('UTC'));
        $start = $end->modify('-' . ($days - 1) . ' days');
        $totals = $this->meals->dailyTotals($user, $start->format('Y-m-d'), $end->format('Y-m-d'));

        $out = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $out[] = ['day' => $key] + ($totals[$key] ?? ['kcal' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0, 'meals' => 0]);
        }
        return $this->json(['goals' => $user->getGoals(), 'days' => $out]);
    }
}
