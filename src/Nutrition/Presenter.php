<?php

namespace App\Nutrition;

use App\Entity\Meal;
use App\Entity\User;
use App\Repository\WeightEntryRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The API's JSON shapes for users and meals, in one place. */
final class Presenter
{
    public function __construct(private UrlGeneratorInterface $urls, private WeightEntryRepository $weights, private GoalCalculator $calculator)
    {
    }

    public function user(User $user): array
    {
        $weightKg = $this->weights->latest($user)?->getWeightKg();
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'timezone' => $user->getTimezone(),
            'profile' => [
                'sex' => $user->getSex(),
                'birth_date' => $user->getBirthDate()?->format('Y-m-d'),
                'height_cm' => $user->getHeightCm(),
                'weight_kg' => $weightKg,
                'activity' => $user->getActivity(),
                'goal' => $user->getGoal(),
                'target_weight_kg' => $user->getTargetWeightKg(),
                'complete' => $user->hasProfile() && $weightKg !== null,
            ],
            'goals' => $user->getGoals() + ['manual' => $user->areGoalsManual()],
            // How the automatic goal is worked out (also shown when the goals are manual), null without a profile.
            'goal_breakdown' => $this->calculator->forUser($user, $weightKg, new \DateTimeImmutable('now', $user->zone())),
        ];
    }

    public function meal(Meal $meal): array
    {
        return [
            'id' => $meal->getId(),
            'name' => $meal->getName(),
            'eaten_at' => $meal->getEatenAt()->setTimezone($meal->getUser()->zone())->format(\DateTimeInterface::RFC3339),
            'day' => $meal->getDay(),
            'source' => $meal->getSource(),
            'description' => $meal->getDescription(),
            'photo_url' => $meal->getPhoto() ? $this->urls->generate('meal_photo', ['id' => $meal->getId(), 'v' => $meal->getPhoto()]) : null,
            'thumb_url' => $meal->getPhoto() ? $this->urls->generate('meal_photo', ['id' => $meal->getId(), 'size' => 'thumb', 'v' => $meal->getPhoto()]) : null,
            'health_score' => $meal->getHealthScore(),
            'confidence' => $meal->getConfidence(),
            'notes' => $meal->getNotes(),
            'ai_model' => $meal->getAiModel(),
            'items' => array_map(fn ($i) => $i->toArray(), $meal->getItems()),
            'totals' => self::round($meal->totals()),
        ];
    }

    /** @param array<string, float> $values */
    public static function round(array $values): array
    {
        return array_map(fn ($v) => round($v, 1), $values);
    }
}
