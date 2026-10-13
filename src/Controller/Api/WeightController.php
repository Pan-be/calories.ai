<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ApiError;
use App\Http\Dates;
use App\Http\Input;
use App\Nutrition\Presenter;
use App\Nutrition\Profiles;
use App\Repository\WeightEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/weights')]
final class WeightController extends AbstractController
{
    public function __construct(private WeightEntryRepository $weights, private Profiles $profiles)
    {
    }

    /** ?days=90: entries oldest first. */
    #[Route('', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $days = max(1, min(3650, $request->query->getInt('days', 90)));
        $from = (new \DateTimeImmutable('now', $user->zone()))->modify("-$days days")->format('Y-m-d');

        return $this->json([
            'target_weight_kg' => $user->getTargetWeightKg(),
            'entries' => array_map(fn ($w) => $w->toArray(), $this->weights->since($user, $from)),
        ]);
    }

    /** {"weight_kg", "day"?}: one entry per day, a second one replaces it. */
    #[Route('', methods: ['POST'])]
    public function add(Request $request, #[CurrentUser] User $user, Presenter $presenter): JsonResponse
    {
        $in = Input::json($request);
        $kg = $in->number('weight_kg', 25, 400);
        $day = $in->date('day', false)?->format('Y-m-d') ?? $this->profiles->today($user);
        if ($day > $this->profiles->today($user)) {
            throw ApiError::invalid('Nie można wpisać wagi z przyszłości.');
        }
        $entry = $this->profiles->recordWeight($user, $day, $kg);

        return $this->json(['entry' => $entry->toArray(), 'user' => $presenter->user($user)], 201);
    }

    #[Route('/{day}', methods: ['DELETE'])]
    public function delete(string $day, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        if (Dates::day($day) === null) {
            throw ApiError::invalid('Data musi mieć postać RRRR-MM-DD.');
        }
        $entry = $this->weights->findOneBy(['user' => $user, 'day' => $day]) ?? throw ApiError::notFound();
        $em->remove($entry);
        $em->flush();
        $this->profiles->refreshGoals($user);

        return $this->json(['ok' => true]);
    }
}
