<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\RateLimit;

use Nowo\HttpHardeningBundle\RateLimit\PerMinuteRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PerMinuteRateLimiterTest extends TestCase
{
    public function testAcceptsUpToTheLimitThenRejects(): void
    {
        $limiter = new PerMinuteRateLimiter(new ArrayAdapter());

        self::assertTrue($limiter->accept('hooks', 'k', 2));
        self::assertTrue($limiter->accept('hooks', 'k', 2));
        self::assertFalse($limiter->accept('hooks', 'k', 2));
    }

    public function testBucketsAreSeparatedByIdAndKey(): void
    {
        $limiter = new PerMinuteRateLimiter(new ArrayAdapter());

        self::assertTrue($limiter->accept('hooks', 'a', 1));
        self::assertFalse($limiter->accept('hooks', 'a', 1));
        self::assertTrue($limiter->accept('hooks', 'b', 1), 'another key');
        self::assertTrue($limiter->accept('api', 'a', 1), 'another family');
    }

    public function testTheLimitIsReadAtEachCall(): void
    {
        $limiter = new PerMinuteRateLimiter(new ArrayAdapter());

        self::assertTrue($limiter->accept('hooks', 'k', 1));
        self::assertFalse($limiter->accept('hooks', 'k', 1));
        self::assertTrue($limiter->accept('hooks', 'k', 5), 'a raised runtime limit applies to the same bucket');
    }

    public function testZeroOrNegativeLimitDisablesTheLimiter(): void
    {
        $limiter = new PerMinuteRateLimiter(new ArrayAdapter());

        self::assertNull($limiter->consume('hooks', 'k', 0));
        self::assertNull($limiter->consume('hooks', 'k', -1));
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($limiter->accept('hooks', 'k', 0));
        }
    }

    public function testConsumeExposesRemainingTokensAndRetryAfter(): void
    {
        $limiter = new PerMinuteRateLimiter(new ArrayAdapter());

        $first = $limiter->consume('hooks', 'k', 2);
        self::assertNotNull($first);
        self::assertTrue($first->isAccepted());
        self::assertSame(1, $first->getRemainingTokens());
        self::assertSame(2, $first->getLimit());

        $limiter->consume('hooks', 'k', 2);
        $rejected = $limiter->consume('hooks', 'k', 2);
        self::assertNotNull($rejected);
        self::assertFalse($rejected->isAccepted());
        self::assertGreaterThan(time(), $rejected->getRetryAfter()->getTimestamp());
        self::assertLessThanOrEqual(time() + 61, $rejected->getRetryAfter()->getTimestamp());
    }

    public function testIpKeyIsHashedAndStable(): void
    {
        $key = PerMinuteRateLimiter::ipKey('203.0.113.7');

        self::assertSame('ip_'.hash('xxh128', '203.0.113.7'), $key);
        self::assertStringNotContainsString('203.0.113.7', $key);
        self::assertSame($key, PerMinuteRateLimiter::ipKey('203.0.113.7'));
        self::assertNotSame($key, PerMinuteRateLimiter::ipKey('203.0.113.8'));
        self::assertSame('ip_'.hash('xxh128', 'unknown'), PerMinuteRateLimiter::ipKey(''));
    }
}
