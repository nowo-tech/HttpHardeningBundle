<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\RateLimit;

use Nowo\HttpHardeningBundle\Model\RateLimitDecision;
use Nowo\HttpHardeningBundle\RateLimit\PerMinuteRateLimiter;
use Nowo\HttpHardeningBundle\RateLimit\PerMinuteRateLimiterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PerMinuteRateLimiterAttemptTest extends TestCase
{
    public function testAttemptReturnsAFrameworkFreeDecision(): void
    {
        $limiter = $this->limiter();

        $first = $limiter->attempt('guard', 'k', 2);
        self::assertInstanceOf(RateLimitDecision::class, $first);
        self::assertTrue($first->accepted);
        self::assertSame(2, $first->limit);
        self::assertSame(1, $first->remaining);

        self::assertTrue($limiter->attempt('guard', 'k', 2)?->accepted);
        $third = $limiter->attempt('guard', 'k', 2);
        self::assertFalse($third->accepted);
        self::assertSame(0, $third->remaining);
        self::assertGreaterThan(time() - 1, $third->retryAfter->getTimestamp());
    }

    public function testAttemptMatchesConsume(): void
    {
        $a = new PerMinuteRateLimiter(new ArrayAdapter());
        $b = new PerMinuteRateLimiter(new ArrayAdapter());
        for ($i = 0; $i < 5; ++$i) {
            $state = $a->consume('g', 'k', 3);
            $decision = $b->attempt('g', 'k', 3);
            self::assertNotNull($state);
            self::assertInstanceOf(RateLimitDecision::class, $decision);
            self::assertSame($state->isAccepted(), $decision->accepted);
            self::assertSame($state->getRemainingTokens(), $decision->remaining);
        }
    }

    public function testDisabledLimiterReturnsNull(): void
    {
        $limiter = new PerMinuteRateLimiter(new ArrayAdapter());

        self::assertNull($limiter->attempt('g', 'k', 0));
        self::assertNull($limiter->attempt('g', 'k', -1));
    }

    private function limiter(): PerMinuteRateLimiterInterface
    {
        return new PerMinuteRateLimiter(new ArrayAdapter());
    }
}
