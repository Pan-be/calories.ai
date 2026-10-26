<?php

namespace App\Controller\Api;

use App\Ai\AnalysisQuota;
use App\Ai\AnalysisRequest;
use App\Ai\MealAnalysis;
use App\Ai\MealAnalyzer;
use App\Entity\Meal;
use App\Entity\MealItem;
use App\Entity\User;
use App\Http\ApiError;
use App\Http\Input;
use App\Nutrition\Presenter;
use App\Photo\PhotoStore;
use App\Recipe\RecipeBook;
use App\Recipe\RecipeServing;
use App\Repository\MealRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/meals')]
final class MealController extends AbstractController
{
    private const MAX_ITEMS = 30;

    public function __construct(
        private EntityManagerInterface $em,
        private MealRepository $meals,
        private MealAnalyzer $analyzer,
        private PhotoStore $photos,
        private Presenter $presenter,
        private AnalysisQuota $quota,
        private RecipeBook $recipes,
    ) {
    }

    /** Multipart: photo (file), note (optional text), eaten_at (optional ISO 8601). */
    #[Route('/photo', methods: ['POST'])]
    public function fromPhoto(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $file = $request->files->get('photo');
        if ($file === null) {
            throw ApiError::invalid('Dołącz zdjęcie posiłku.');
        }
        $in = Input::form($request);
        $note = $in->string('note', 1000, false);
        $eatenAt = $in->moment('eaten_at') ?? new \DateTimeImmutable();
        $this->quota->consume($user);

        $photo = $this->photos->store($file);
        try {
            $analysis = $this->analyzer->analyze(new AnalysisRequest(photoJpeg: $this->photos->bytes($photo), description: $note));
        } catch (\Throwable $e) {
            $this->photos->delete($photo);
            throw $e;
        }
        if (!$analysis->isFood) {
            $this->photos->delete($photo);
            throw $this->notFood($analysis);
        }

        $meal = new Meal($user, 'photo', $eatenAt);
        $meal->setPhoto($photo);
        $meal->setDescription($note);
        $this->apply($meal, $analysis);
        $this->em->persist($meal);
        $this->em->flush();

        return $this->json(['meal' => $this->presenter->meal($meal)], 201);
    }

    /** {"text": "...", "eaten_at"?}: a meal from a description alone. */
    #[Route('/describe', methods: ['POST'])]
    public function fromText(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $in = Input::json($request);
        $text = $in->string('text', 2000);
        $eatenAt = $in->moment('eaten_at') ?? new \DateTimeImmutable();
        $this->quota->consume($user);

        $analysis = $this->analyzer->analyze(new AnalysisRequest(description: $text));
        if (!$analysis->isFood) {
            throw $this->notFood($analysis);
        }
        $meal = new Meal($user, 'text', $eatenAt);
        $meal->setDescription($text);
        $this->apply($meal, $analysis);
        $this->em->persist($meal);
        $this->em->flush();

        return $this->json(['meal' => $this->presenter->meal($meal)], 201);
    }

    /**
     * Multipart: recipe_id, portions (e.g. 0.5) or grams, eaten_at (optional), photo (optional).
     * Values come from the recipe, no AI; the photo is only kept with the meal.
     */
    #[Route('/recipe', methods: ['POST'])]
    public function fromRecipe(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $in = Input::form($request);
        $recipe = $this->recipes->get($in->string('recipe_id', 40)) ?? throw ApiError::invalid('Nie ma takiego przepisu.');
        $grams = $in->number('grams', 1, 5000, false);
        $serving = $grams !== null
            ? RecipeServing::grams($recipe, $grams)
            : RecipeServing::portions($recipe, $in->number('portions', 0.1, 10, false) ?? 1);

        $meal = new Meal($user, 'recipe', $in->moment('eaten_at') ?? new \DateTimeImmutable());
        $meal->setName($recipe['name']);
        $meal->setRecipeId($recipe['id']);
        $meal->setDescription($serving->description());
        $meal->replaceItems([MealItem::fromArray($serving->item())]);
        $file = $request->files->get('photo');
        if ($file !== null) {
            $meal->setPhoto($this->photos->store($file));
        }
        $this->em->persist($meal);
        $this->em->flush();

        return $this->json(['meal' => $this->presenter->meal($meal)], 201);
    }

    /**
     * {"name", "items": [...], "eaten_at"?, "source"?}: a meal entered by hand, no AI, or a
     * picked meal suggestion (source "suggestion").
     */
    #[Route('', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $in = Input::json($request);
        $source = $in->choice('source', ['manual', 'suggestion'], false) ?? 'manual';
        $meal = new Meal($user, $source, $in->moment('eaten_at') ?? new \DateTimeImmutable());
        $meal->setName($in->string('name', 200));
        $meal->replaceItems($this->items($in));
        $this->em->persist($meal);
        $this->em->flush();

        return $this->json(['meal' => $this->presenter->meal($meal)], 201);
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id, #[CurrentUser] User $user): JsonResponse
    {
        return $this->json(['meal' => $this->presenter->meal($this->find($id, $user))]);
    }

    /** Any of: name, eaten_at, items (the full new list). */
    #[Route('/{id<\d+>}', methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $meal = $this->find($id, $user);
        $in = Input::json($request);
        if ($in->has('name')) {
            $meal->setName($in->string('name', 200));
        }
        if ($in->has('eaten_at')) {
            $meal->setEatenAt($in->moment('eaten_at'));
        }
        if ($in->has('items')) {
            $meal->replaceItems($this->items($in));
        }
        $meal->touch();
        $this->em->flush();

        return $this->json(['meal' => $this->presenter->meal($meal)]);
    }

    /** {"text": "it was 2 eggs, not 3"}: the AI corrects its own analysis. */
    #[Route('/{id<\d+>}/fix', methods: ['POST'])]
    public function fix(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $meal = $this->find($id, $user);
        $correction = Input::json($request)->string('text', 1000);
        $this->quota->consume($user);

        $analysis = $this->analyzer->analyze(new AnalysisRequest(
            photoJpeg: $meal->getPhoto() ? $this->photos->bytes($meal->getPhoto()) : null,
            description: $meal->getDescription(),
            previous: ['meal_name' => $meal->getName(), 'items' => array_map(fn ($i) => $i->toArray(), $meal->getItems())],
            correction: $correction,
        ));
        if (!$analysis->isFood) {
            throw $this->notFood($analysis);
        }
        $this->apply($meal, $analysis);
        $meal->touch();
        $this->em->flush();

        return $this->json(['meal' => $this->presenter->meal($meal)]);
    }

    /** Log the same meal again (e.g. today's breakfast = yesterday's), photo shared. */
    #[Route('/{id<\d+>}/copy', methods: ['POST'])]
    public function copy(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $source = $this->find($id, $user);
        $meal = new Meal($user, $source->getSource(), Input::json($request)->moment('eaten_at') ?? new \DateTimeImmutable());
        $meal->setPhoto($source->getPhoto());
        $meal->setRecipeId($source->getRecipeId());
        $meal->setName($source->getName());
        $meal->setDescription($source->getDescription());
        $meal->setAnalysisInfo($source->getHealthScore(), $source->getConfidence(), $source->getNotes(), $source->getAiModel(), null);
        $meal->replaceItems(array_map(fn ($i) => MealItem::fromArray($i->toArray()), $source->getItems()));
        $this->em->persist($meal);
        $this->em->flush();

        return $this->json(['meal' => $this->presenter->meal($meal)], 201);
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $meal = $this->find($id, $user);
        $photo = $meal->getPhoto();
        $this->em->remove($meal);
        $this->em->flush();
        if ($photo !== null && $this->meals->count(['photo' => $photo]) === 0) {
            $this->photos->delete($photo);
        }
        return $this->json(['ok' => true]);
    }

    #[Route('/{id<\d+>}/photo', name: 'meal_photo', methods: ['GET'])]
    public function photo(int $id, Request $request, #[CurrentUser] User $user): BinaryFileResponse
    {
        $meal = $this->find($id, $user);
        if ($meal->getPhoto() === null) {
            throw ApiError::notFound();
        }
        $path = $this->photos->path($meal->getPhoto(), $request->query->get('size') === 'thumb');
        if (!is_file($path)) {
            throw ApiError::notFound();
        }
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'image/jpeg']);
        // The URL carries the photo id (?v=), so a cached copy never goes stale.
        $response->setPrivate();
        $response->setMaxAge(30 * 86400);

        return $response;
    }

    private function find(int $id, User $user): Meal
    {
        return $this->meals->findOneBy(['id' => $id, 'user' => $user]) ?? throw ApiError::notFound();
    }

    private function apply(Meal $meal, MealAnalysis $analysis): void
    {
        $meal->setName($analysis->name);
        $meal->replaceItems(array_map(MealItem::fromArray(...), $analysis->items));
        $meal->setAnalysisInfo($analysis->healthScore, $analysis->confidence, $analysis->notes ?: null, $analysis->model, $analysis->raw);
    }

    /** @return list<MealItem> */
    private function items(Input $in): array
    {
        $items = [];
        foreach ($in->list('items', self::MAX_ITEMS) as $raw) {
            $item = Input::of($raw);
            $items[] = MealItem::fromArray([
                'name' => $item->string('name', 200),
                'portion' => $item->string('portion', 100, false) ?? '',
                'grams' => $item->number('grams', 0, 5000, false) ?? 0,
                'kcal' => $item->number('kcal', 0, 10000),
                'protein_g' => $item->number('protein_g', 0, 1000, false) ?? 0,
                'carbs_g' => $item->number('carbs_g', 0, 1000, false) ?? 0,
                'fat_g' => $item->number('fat_g', 0, 1000, false) ?? 0,
            ]);
        }
        if ($items === []) {
            throw ApiError::invalid('Posiłek musi mieć co najmniej jeden składnik.');
        }
        return $items;
    }

    private function notFood(MealAnalysis $analysis): ApiError
    {
        return new ApiError(422, 'not_food', $analysis->notes !== '' ? $analysis->notes : 'Nie widzę tu jedzenia.');
    }
}
