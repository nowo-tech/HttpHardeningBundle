<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Fixtures;

use Nowo\HttpHardeningBundle\RateLimit\RateLimitProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A host limit provider: a fixed value standing in for a setting read from the database.
 */
final readonly class FixedRateLimitProvider implements RateLimitProviderInterface
{
    public function __construct(
        private int $limit,
    ) {
    }

    public function limitPerMinute(string $rule, Request $request): int
    {
        return $this->limit;
    }
}
