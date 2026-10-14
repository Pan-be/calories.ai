<?php

namespace App\Ai;

use App\Entity\User;
use App\Http\ApiError;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Per-user hourly cap on AI calls (KALORIE_ANALYSES_PER_HOUR), shared by analyses and suggestions. */
final class AnalysisQuota
{
    public function __construct(private RateLimiterFactoryInterface $analysisLimiter)
    {
    }

    public function consume(User $user): void
    {
        $limit = $this->analysisLimiter->create((string) $user->getId())->consume();
        if (!$limit->isAccepted()) {
            throw new ApiError(429, 'too_many_analyses', 'Za dużo zapytań do AI w ostatniej godzinie. Spróbuj później albo dodaj posiłek ręcznie.',
                ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
        }
    }
}
