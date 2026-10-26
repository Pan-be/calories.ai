<?php

namespace App\Recipe;

use App\Entity\CookidooRecipe;
use App\Http\ApiError;
use App\Repository\CookidooRecipeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A Cookidoo recipe from a link (or the text the Cookidoo app shares, link included).
 * Cookidoo has no public API, but each recipe page carries schema.org Recipe data
 * (values per portion, number of portions, ingredients with grams) without a login.
 * A recipe is fetched once and then kept in the database.
 *
 * @phpstan-import-type Ingredient from RecipeBook
 * @phpstan-import-type Recipe from RecipeBook
 */
final class CookidooImporter
{
    private const LINK = '~https?://(?:www\.)?(cookidoo\.(?:pl|de|at|ch|fr|it|es|pt|be|nl|cz|co\.uk|com|com\.au|international))/recipes/recipe/([a-z]{2}-[A-Z]{2})/(r\d{1,9})\b~';
    private const MAX_BYTES = 2_000_000;
    /** Grams per unit for the amounts Cookidoo doesn't give in grams. */
    private const UNITS = [
        'ząb' => 5, 'łyżecz' => 5, 'łyżk' => 15, 'łyżek' => 15, 'szczypt' => 0.5,
    ];

    public function __construct(
        private HttpClientInterface $http,
        private CookidooRecipeRepository $recipes,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /** @return Recipe */
    public function import(string $text): array
    {
        if (preg_match(self::LINK, $text, $m) !== 1) {
            throw ApiError::invalid('To nie jest link do przepisu z Cookidoo.');
        }
        $id = CookidooRecipe::ID_PREFIX . $m[3];
        $known = $this->recipes->find($id);
        if ($known !== null) {
            return $known->toArray();
        }
        $url = "https://{$m[1]}/recipes/recipe/{$m[2]}/{$m[3]}";
        $recipe = self::parse($this->fetch($url), $id, $url);
        try {
            $this->em->persist($recipe);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // The same link imported by a parallel request in the meantime: same page, same data.
        }

        return $recipe->toArray();
    }

    /** The recipe from a page's schema.org data; ApiError if it has no values per portion. */
    public static function parse(string $html, string $id, string $url): CookidooRecipe
    {
        $data = null;
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $blocks);
        foreach ($blocks[1] as $block) {
            $json = json_decode($block, true);
            if (is_array($json) && ($json['@type'] ?? null) === 'Recipe') {
                $data = $json;
                break;
            }
        }
        $n = is_array($data['nutrition'] ?? null) ? $data['nutrition'] : [];
        if ($data === null || !isset($n['calories'])) {
            throw ApiError::invalid('Ten przepis z Cookidoo nie ma wartości odżywczych.');
        }
        $yield = $data['recipeYield'] ?? 1;
        $portions = max(1, (int) self::number(is_array($yield) ? implode(' ', array_filter($yield, 'is_scalar')) : $yield));
        $ingredients = array_map(self::ingredient(...), array_values(array_filter((array) ($data['recipeIngredient'] ?? []), 'is_string')));
        foreach ($ingredients as $i => $ingredient) {
            $ingredients[$i]['grams'] = round($ingredient['grams'] / $portions, 1);
        }

        return new CookidooRecipe(
            $id, html_entity_decode((string) ($data['name'] ?? 'Przepis z Cookidoo')),
            self::number($n['calories']), self::number($n['proteinContent'] ?? 0),
            self::number($n['carbohydrateContent'] ?? 0), self::number($n['fatContent'] ?? 0),
            round(array_sum(array_column($ingredients, 'grams')), 1), $portions, $ingredients, $url,
        );
    }

    /**
     * "110 g cebuli", "15 - 20 g korzenia imbiru" (the middle), "2 ząbki czosnku" (5 g each),
     * "1 łyżeczka soli"; anything else counts as 0 g, the values per portion don't depend on it.
     *
     * @return Ingredient
     */
    private static function ingredient(string $line): array
    {
        $line = trim(preg_replace('/\s+/u', ' ', html_entity_decode($line)) ?? '');
        $num = '(\d+(?:[.,]\d+)?|½|¼|¾)';
        if (preg_match("~^{$num}(?:\s*-\s*{$num})?\s*(kg|g|ml|l)\s+(.+)$~u", $line, $m) === 1) {
            $amount = $m[2] !== '' ? (self::number($m[1]) + self::number($m[2])) / 2 : self::number($m[1]);
            $grams = $amount * (in_array($m[3], ['kg', 'l'], true) ? 1000 : 1);
            return ['name' => $m[4], 'amount' => '', 'grams' => $grams];
        }
        if (preg_match("~^({$num}(?:\s*-\s*{$num})?\s*(\S+))\s+(.+)$~u", $line, $m) === 1) {
            $count = $m[3] !== '' ? (self::number($m[2]) + self::number($m[3])) / 2 : self::number($m[2]);
            foreach (self::UNITS as $unit => $grams) {
                if (str_starts_with(mb_strtolower($m[4]), $unit)) {
                    return ['name' => $m[5], 'amount' => $m[1], 'grams' => $count * $grams];
                }
            }
            return ['name' => $m[5], 'amount' => $m[1], 'grams' => 0.0];
        }
        return ['name' => $line, 'amount' => '', 'grams' => 0.0];
    }

    private static function number(mixed $value): float
    {
        $value = trim((string) $value);
        $fractions = ['½' => 0.5, '¼' => 0.25, '¾' => 0.75];
        if (isset($fractions[$value])) {
            return $fractions[$value];
        }
        return preg_match('/\d+(?:[.,]\d+)?/', $value, $m) === 1 ? (float) str_replace(',', '.', $m[0]) : 0.0;
    }

    private function fetch(string $url): string
    {
        try {
            // No redirects: the request only ever goes to the allowlisted URL built above.
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => 'Kalorie/1.0 (personal calorie app)', 'Accept' => 'text/html'],
                'timeout' => 10,
                'max_duration' => 15,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                $this->logger->warning('Cookidoo fetch failed: HTTP {status} for {url}', ['status' => $status, 'url' => $url]);
                throw ApiError::invalid($status === 404 ? 'Nie ma takiego przepisu w Cookidoo.' : 'Nie udało się pobrać przepisu z Cookidoo.');
            }
            $html = $response->getContent();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Cookidoo fetch failed: {error} for {url}', ['error' => $e::class, 'url' => $url]);
            throw ApiError::invalid('Nie udało się pobrać przepisu z Cookidoo.');
        }
        if (strlen($html) > self::MAX_BYTES) {
            throw ApiError::invalid('Nie udało się pobrać przepisu z Cookidoo.');
        }
        return $html;
    }
}
