<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\Input;
use App\Recipe\CookidooImporter;
use App\Recipe\RecipeBook;
use App\Repository\MealRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/recipes')]
final class RecipeController extends AbstractController
{
    private const LIMIT = 30;
    private const RECENT = 10;

    public function __construct(private RecipeBook $book, private MealRepository $meals, private CookidooImporter $cookidoo)
    {
    }

    /** ?q=: recipes matching the words; without q, the ones the user logged lately. */
    #[Route('', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $query = mb_substr(trim((string) $request->query->get('q', '')), 0, 100);
        if ($query !== '') {
            return $this->json(['recipes' => $this->book->search($query, self::LIMIT), 'recent' => false]);
        }
        $recent = array_values(array_filter(array_map(
            $this->book->get(...),
            $this->meals->recentRecipeIds($user, self::RECENT),
        )));

        return $this->json(['recipes' => $recent, 'recent' => true]);
    }

    /** {"link"}: a Cookidoo recipe link, or the text the Cookidoo app shares with it. */
    #[Route('/cookidoo', methods: ['POST'])]
    public function cookidoo(Request $request): JsonResponse
    {
        return $this->json(['recipe' => $this->cookidoo->import(Input::json($request)->string('link', 1000))]);
    }
}
