<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\Workout;
use App\Http\ApiError;
use App\Http\Dates;
use App\Http\Input;
use App\Nutrition\Profiles;
use App\Repository\WorkoutRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/workouts')]
final class WorkoutController extends AbstractController
{
    public function __construct(private WorkoutRepository $workouts, private EntityManagerInterface $em)
    {
    }

    /** ?days=30: entries oldest first, plus the most often typed descriptions (quick picks). */
    #[Route('', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user, Profiles $profiles): JsonResponse
    {
        $days = max(1, min(3650, $request->query->getInt('days', 30)));
        $from = (new \DateTimeImmutable($profiles->today($user)))->modify("-$days days")->format('Y-m-d');

        return $this->json([
            'entries' => array_map(fn ($w) => $w->toArray(), $this->workouts->since($user, $from)),
            'frequent' => $this->workouts->frequentDescriptions($user),
        ]);
    }

    /** {"rest": true} or {"description", "intensity"?}: one entry per day, saving again replaces it. */
    #[Route('/{day}', methods: ['PUT'])]
    public function put(string $day, Request $request, #[CurrentUser] User $user, Profiles $profiles): JsonResponse
    {
        $this->checkDay($day);
        if ($day > $profiles->today($user)) {
            throw ApiError::invalid('Nie można wpisać treningu z przyszłości.');
        }
        $in = Input::json($request);
        $rest = $in->bool('rest');
        $description = $rest ? null : $in->string('description', 500);
        $intensity = $rest ? null : $in->choice('intensity', Workout::INTENSITIES, false);

        $workout = $this->workouts->forDay($user, $day);
        if ($workout === null) {
            $workout = new Workout($user, $day);
            $this->em->persist($workout);
        }
        $workout->set($rest, $description, $intensity);
        $this->em->flush();

        return $this->json(['workout' => $workout->toArray()]);
    }

    #[Route('/{day}', methods: ['DELETE'])]
    public function delete(string $day, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkDay($day);
        $workout = $this->workouts->forDay($user, $day) ?? throw ApiError::notFound();
        $this->em->remove($workout);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    private function checkDay(string $day): void
    {
        if (Dates::day($day) === null) {
            throw ApiError::invalid('Data musi mieć postać RRRR-MM-DD.');
        }
    }
}
