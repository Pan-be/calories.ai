<?php

namespace App\Controller\Api;

use App\Entity\ScaleMeasurement;
use App\Entity\User;
use App\Http\ApiError;
use App\Http\Input;
use App\Nutrition\Presenter;
use App\Nutrition\Profiles;
use App\Repository\ScaleMeasurementRepository;
use App\Repository\WeightEntryRepository;
use App\Scale\ScaleFrame;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Weighings from the Bluetooth scale. The app saves the stable weight first (it also
 * becomes the day's weight), then attaches the impedance frame that follows ~15 s later.
 */
#[Route('/api/scale/measurements')]
final class ScaleController extends AbstractController
{
    public function __construct(private ScaleMeasurementRepository $measurements, private EntityManagerInterface $em)
    {
    }

    /** ?days=90: measurements oldest first. */
    #[Route('', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $days = max(1, min(3650, $request->query->getInt('days', 90)));
        $from = (new \DateTimeImmutable('now', $user->zone()))->modify("-$days days")->format('Y-m-d');

        return $this->json(['measurements' => array_map(fn ($m) => $m->toArray(), $this->measurements->since($user, $from))]);
    }

    /**
     * {"weight_kg"}: a weighing now; the latest one of the day is the day's weight. Send the
     * scale's value unrounded (0.01 kg), the frame is later matched against it.
     */
    #[Route('', methods: ['POST'])]
    public function add(Request $request, #[CurrentUser] User $user, Profiles $profiles, Presenter $presenter): JsonResponse
    {
        $kg = Input::json($request)->number('weight_kg', 25, 400);
        $measurement = new ScaleMeasurement($user, $kg, new \DateTimeImmutable());
        $this->em->persist($measurement);
        $this->em->flush();
        $profiles->recordWeight($user, $measurement->getDay(), $kg);

        return $this->json(['measurement' => $measurement->toArray(), 'user' => $presenter->user($user)], 201);
    }

    /** {"frame": hex}: the FFF3 frame of this weighing; sending it again replaces it. */
    #[Route('/{id<\d+>}/frame', methods: ['PUT'])]
    public function frame(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $measurement = $this->find($id, $user);
        try {
            $frame = ScaleFrame::parse(Input::json($request)->string('frame', 1000) ?? '');
        } catch (\InvalidArgumentException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        if (abs($frame->weightKg - $measurement->getWeightKg()) >= 0.005) {
            throw new ApiError(422, 'frame_mismatch', 'Ta ramka pochodzi z innego ważenia.');
        }
        $measurement->setFrame($frame);
        $this->em->flush();

        return $this->json(['measurement' => $measurement->toArray()]);
    }

    /**
     * Removes a weighing, e.g. someone else stepped on the scale. If the day's weight came
     * from it, the day goes back to its previous weighing, or has no weight without one; a
     * weight typed by hand since then is left alone, one typed before the weighing isn't restored.
     */
    #[Route('/{id<\d+>}', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] User $user, Profiles $profiles, Presenter $presenter, WeightEntryRepository $weights): JsonResponse
    {
        $measurement = $this->find($id, $user);
        $this->em->wrapInTransaction(function () use ($measurement, $user, $profiles, $weights): void {
            $day = $measurement->getDay();
            $this->em->remove($measurement);
            $this->em->flush();

            $entry = $weights->findOneBy(['user' => $user, 'day' => $day]);
            if ($entry !== null && abs($entry->getWeightKg() - $measurement->getWeightKg()) < 0.005) {
                $previous = $this->measurements->latestOnDay($user, $day);
                if ($previous !== null) {
                    $profiles->recordWeight($user, $day, $previous->getWeightKg());
                } else {
                    $this->em->remove($entry);
                    $this->em->flush();
                    $profiles->refreshGoals($user);
                }
            }
        });

        return $this->json(['ok' => true, 'user' => $presenter->user($user)]);
    }

    private function find(int $id, User $user): ScaleMeasurement
    {
        return $this->measurements->findOneBy(['id' => $id, 'user' => $user]) ?? throw ApiError::notFound();
    }
}
