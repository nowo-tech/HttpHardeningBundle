<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\RateLimit;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function hash;

/**
 * One sliding-window "N per minute" rate limiter over a PSR-6 cache pool (`cache.app` by default).
 *
 * The limit is runtime data (a setting stored in the database, a per-project quota, a container parameter), so a
 * static `framework.rate_limiter` pool does not fit: the limiter factory is built per call (cheap) with the limit
 * read at that moment. A limit of `0` (or less) disables the check.
 *
 * Buckets live in the cache pool only; the service keeps no state between requests (FrankenPHP worker-safe).
 * Without a lock factory two concurrent requests may both pass on the very last token: fine for abuse throttling,
 * not for billing quotas.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class PerMinuteRateLimiter
{
    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Consumes one token.
     *
     * @param string $id             limiter family (`webhook_ip`, `api_token`, …): separates the buckets of each guard
     * @param string $key            bucket within the family (see {@see ipKey()})
     * @param int    $limitPerMinute requests allowed per sliding minute; `0` or less disables the limiter
     *
     * @return bool true when the call is within the limit, or when the limiter is disabled
     */
    public function accept(string $id, string $key, int $limitPerMinute): bool
    {
        return $this->consume($id, $key, $limitPerMinute)?->isAccepted() ?? true;
    }

    /**
     * Consumes one token and returns the full rate limit state (remaining tokens, retry-after).
     *
     * @param string $id             limiter family
     * @param string $key            bucket within the family
     * @param int    $limitPerMinute requests allowed per sliding minute; `0` or less disables the limiter
     *
     * @return RateLimit|null null when the limiter is disabled
     */
    public function consume(string $id, string $key, int $limitPerMinute): ?RateLimit
    {
        if ($limitPerMinute <= 0) {
            return null;
        }

        $factory = new RateLimiterFactory([
            'id' => $id,
            'policy' => 'sliding_window',
            'limit' => $limitPerMinute,
            'interval' => '1 minute',
        ], new CacheStorage($this->cache));

        return $factory->create($key)->consume(1);
    }

    /**
     * Hashed client IP bucket (an empty IP becomes `unknown`): the raw address never reaches the cache keys.
     */
    public static function ipKey(string $clientIp): string
    {
        return 'ip_'.hash('xxh128', '' !== $clientIp ? $clientIp : 'unknown');
    }
}
