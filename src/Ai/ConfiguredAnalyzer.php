<?php

namespace App\Ai;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The MealAnalyzer the app uses: the implementation named by KALORIE_ANALYZER. */
#[AsAlias(MealAnalyzer::class)]
final class ConfiguredAnalyzer implements MealAnalyzer
{
    public function __construct(
        #[AutowireLocator(['claude' => ClaudeBridgeAnalyzer::class, 'fake' => FakeAnalyzer::class])]
        private ContainerInterface $analyzers,
        #[Autowire(env: 'KALORIE_ANALYZER')] private string $name,
    ) {
    }

    public function analyze(AnalysisRequest $request): MealAnalysis
    {
        return $this->engine()->analyze($request);
    }

    public function suggest(SuggestionRequest $request): MealSuggestions
    {
        return $this->engine()->suggest($request);
    }

    private function engine(): MealAnalyzer
    {
        if (!$this->analyzers->has($this->name)) {
            throw new \LogicException("Unknown KALORIE_ANALYZER: {$this->name}");
        }
        return $this->analyzers->get($this->name);
    }
}
