<?php

namespace App\Nutrition;

use App\Entity\User;

/**
 * Daily calorie and macro goals from the body profile, the way calorie apps usually do it:
 * Mifflin-St Jeor BMR x activity factor = TDEE, then a deficit or surplus for the goal.
 * Protein per kg (more while cutting), 27% of calories from fat, the rest carbs.
 */
final class GoalCalculator
{
    private const ACTIVITY_FACTOR = [
        'sedentary' => 1.2, 'light' => 1.375, 'moderate' => 1.55, 'active' => 1.725, 'very_active' => 1.9,
    ];
    private const PROTEIN_PER_KG = ['lose' => 2.0, 'maintain' => 1.6, 'gain' => 1.8];
    private const FAT_SHARE = 0.27;

    /** @return array{kcal:int, protein_g:int, carbs_g:int, fat_g:int, bmr:int, tdee:int}|null null if the profile or weight is missing */
    public function forUser(User $user, ?float $weightKg, \DateTimeImmutable $today): ?array
    {
        if (!$user->hasProfile() || $weightKg === null) {
            return null;
        }
        $age = $user->getBirthDate()->diff($today)->y;
        $height = $user->getHeightCm();

        $bmr = 10 * $weightKg + 6.25 * $height - 5 * $age + ($user->getSex() === 'male' ? 5 : -161);
        $tdee = $bmr * self::ACTIVITY_FACTOR[$user->getActivity()];
        $kcal = match ($user->getGoal()) {
            'lose' => $tdee - min(500, $tdee * 0.2),
            'gain' => $tdee + 300,
            default => $tdee,
        };
        $kcal = max($kcal, $user->getSex() === 'male' ? 1500 : 1200);
        $kcal = (int) (round($kcal / 10) * 10);

        // For high body weight, protein follows the weight at BMI 27, not the full weight.
        $proteinWeight = min($weightKg, 27 * ($height / 100) ** 2);
        $protein = (int) round($proteinWeight * self::PROTEIN_PER_KG[$user->getGoal()]);
        $fat = (int) round($kcal * self::FAT_SHARE / 9);
        $carbs = (int) max(50, round(($kcal - $protein * 4 - $fat * 9) / 4));

        return ['kcal' => $kcal, 'protein_g' => $protein, 'carbs_g' => $carbs, 'fat_g' => $fat, 'bmr' => (int) round($bmr), 'tdee' => (int) round($tdee)];
    }
}
