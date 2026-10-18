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

    /**
     * {"description", "intensity"?, "part_of_day"?}: one more training that day (and the day
     * stops being a rest day), or {"rest": true}: a rest day, only while the day has no training.
     */
    #[Route('/{day<\d{4}-\d{2}-\d{2}>}', methods: ['POST'])]
    public function add(string $day, Request $request, #[CurrentUser] User $user, Profiles $profiles): JsonResponse
    {
        $this->checkDay($day, $user, $profiles);
        $in = Input::json($request);
        $existing = $this->workouts->forDay($user, $day);
        if ($in->bool('rest')) {
            foreach ($existing as $entry) {
                if (!$entry->isRest()) {
                    throw ApiError::invalid('Ten dzień ma już trening. Usuń go, żeby zapisać odpoczynek.');
                }
                return $this->json(['workout' => $entry->toArray()]);
            }
            $workout = new Workout($user, $day);
            $workout->set(true, null, null);
        } else {
            $workout = new Workout($user, $day);
            $this->fill($workout, $in);
            foreach ($existing as $entry) {
                if ($entry->isRest()) {
                    $this->em->remove($entry);
                }
            }
        }
        $this->em->persist($workout);
        $this->em->flush();

        return $this->json(['workout' => $workout->toArray()], 201);
    }

    /** {"description", "intensity"?, "part_of_day"?}: replaces those three on one training. */
    #[Route('/{id<\d+>}', methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $workout = $this->find($id, $user);
        if ($workout->isRest()) {
            throw ApiError::invalid('Dzień odpoczynku nie ma opisu. Usuń go i dodaj trening.');
        }
        $this->fill($workout, Input::json($request));
        $this->em->flush();

        return $this->json(['workout' => $workout->toArray()]);
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->em->remove($this->find($id, $user));
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /** Older app versions: {"rest": true} or {"description", "intensity"?} replaces the whole day. */
    #[Route('/{day<\d{4}-\d{2}-\d{2}>}', methods: ['PUT'])]
    public function replaceDay(string $day, Request $request, #[CurrentUser] User $user, Profiles $profiles): JsonResponse
    {
        $this->checkDay($day, $user, $profiles);
        $in = Input::json($request);
        $workout = new Workout($user, $day);
        if ($in->bool('rest')) {
            $workout->set(true, null, null);
        } else {
            $this->fill($workout, $in);
        }
        foreach ($this->workouts->forDay($user, $day) as $entry) {
            $this->em->remove($entry);
        }
        $this->em->persist($workout);
        $this->em->flush();

        return $this->json(['workout' => $workout->toArray()]);
    }

    private function fill(Workout $workout, Input $in): void
    {
        $workout->set(
            false,
            $in->string('description', 500),
            $in->choice('intensity', Workout::INTENSITIES, false),
            $in->choice('part_of_day', Workout::PARTS_OF_DAY, false),
        );
    }

    private function find(int $id, User $user): Workout
    {
        return $this->workouts->findOneBy(['id' => $id, 'user' => $user]) ?? throw ApiError::notFound();
    }

    private function checkDay(string $day, User $user, Profiles $profiles): void
    {
        if (Dates::day($day) === null) {
            throw ApiError::invalid('Data musi mieć postać RRRR-MM-DD.');
        }
        if ($day > $profiles->today($user)) {
            throw ApiError::invalid('Nie można wpisać treningu z przyszłości.');
        }
    }
}
