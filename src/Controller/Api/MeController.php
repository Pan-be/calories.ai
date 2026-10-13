<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ApiError;
use App\Http\Input;
use App\Nutrition\Presenter;
use App\Nutrition\Profiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/me')]
final class MeController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private Presenter $presenter, private Profiles $profiles)
    {
    }

    #[Route('', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(['user' => $this->presenter->user($user)]);
    }

    /** Body profile; the weight goes to the weight log as today's entry. */
    #[Route('/profile', methods: ['PUT'])]
    public function profile(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $in = Input::json($request);
        $sex = $in->choice('sex', User::SEXES);
        $birth = $in->date('birth_date');
        $age = $birth->diff(new \DateTimeImmutable())->y;
        if ($birth > new \DateTimeImmutable() || $age < 13 || $age > 110) {
            throw ApiError::invalid('Podaj prawdziwą datę urodzenia (wiek 13–110 lat).');
        }
        $height = $in->number('height_cm', 100, 250);
        $weight = $in->number('weight_kg', 25, 400);
        $activity = $in->choice('activity', User::ACTIVITY);
        $goal = $in->choice('goal', User::GOALS);
        $target = $in->number('target_weight_kg', 25, 400, false);
        $timezone = $in->string('timezone', 64, false);
        if ($timezone !== null) {
            if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                throw ApiError::invalid('Nieznana strefa czasowa.');
            }
            $user->setTimezone($timezone);
        }

        $user->setSex($sex);
        $user->setBirthDate($birth);
        $user->setHeightCm($height);
        $user->setActivity($activity);
        $user->setGoal($goal);
        $user->setTargetWeightKg($target);
        $this->em->flush();
        $this->profiles->recordWeight($user, $this->profiles->today($user), $weight);
        $computed = $this->profiles->refreshGoals($user, $in->bool('reset_goals'));

        return $this->json(['user' => $this->presenter->user($user), 'computed' => $computed]);
    }

    /** Goals set by hand, or {"auto": true} to go back to computed goals. */
    #[Route('/goals', methods: ['PUT'])]
    public function goals(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $in = Input::json($request);
        if ($in->bool('auto')) {
            $computed = $this->profiles->refreshGoals($user, true);
            if ($computed === null) {
                throw ApiError::invalid('Najpierw uzupełnij profil, żeby policzyć cele.');
            }
            return $this->json(['user' => $this->presenter->user($user), 'computed' => $computed]);
        }

        $user->setGoals([
            'kcal' => (int) $in->number('kcal', 800, 8000),
            'protein_g' => (int) $in->number('protein_g', 0, 500),
            'carbs_g' => (int) $in->number('carbs_g', 0, 1000),
            'fat_g' => (int) $in->number('fat_g', 0, 400),
        ], true);
        $this->em->flush();

        return $this->json(['user' => $this->presenter->user($user)]);
    }

    #[Route('/password', methods: ['PUT'])]
    public function password(Request $request, #[CurrentUser] User $user, UserPasswordHasherInterface $hasher): JsonResponse
    {
        $in = Input::json($request);
        if (!$hasher->isPasswordValid($user, $in->string('current_password', 200))) {
            throw ApiError::invalid('Obecne hasło jest nieprawidłowe.');
        }
        $new = $in->string('new_password', 200);
        if (mb_strlen($new) < 8) {
            throw ApiError::invalid('Nowe hasło musi mieć co najmniej 8 znaków.');
        }
        $user->setPassword($hasher->hashPassword($user, $new));
        $this->em->flush();

        return $this->json(['ok' => true]);
    }
}
