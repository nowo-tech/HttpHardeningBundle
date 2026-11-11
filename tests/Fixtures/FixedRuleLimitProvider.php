<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Fixtures;

use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;
use Nowo\HttpHardeningBundle\RateLimit\RuleLimitProviderInterface;

/**
 * Framework-free host limit provider: a fixed value, recording the request snapshots it saw.
 */
final class FixedRuleLimitProvider implements RuleLimitProviderInterface
{
    /** @var list<HttpRequestInfo> */
    public array $seen = [];

    public function __construct(
        private readonly int $limit,
    ) {
    }

    public function limitPerMinute(string $rule, HttpRequestInfo $request): int
    {
        $this->seen[] = $request;

        return $this->limit;
    }
}
