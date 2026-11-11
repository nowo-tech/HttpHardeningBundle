<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\RateLimit;

use Nowo\HttpHardeningBundle\Model\RateLimitDecision;

/**
 * "N per minute" sliding-window limiter with the limit read at call time (autowirable; implemented by
 * {@see PerMinuteRateLimiter}).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface PerMinuteRateLimiterInterface
{
    /**
     * Consumes one token.
     *
     * @param string $id             limiter family (`webhook_ip`, `api_token`, …): separates the buckets of each guard
     * @param string $key            bucket within the family (see {@see PerMinuteRateLimiter::ipKey()})
     * @param int    $limitPerMinute requests allowed per sliding minute; `0` or less disables the limiter
     *
     * @return bool true when the call is within the limit, or when the limiter is disabled
     */
    public function accept(string $id, string $key, int $limitPerMinute): bool;

    /**
     * Consumes one token and returns the full state (remaining tokens, retry-after).
     *
     * @return RateLimitDecision|null null when the limiter is disabled (limit `0` or less)
     */
    public function attempt(string $id, string $key, int $limitPerMinute): ?RateLimitDecision;
}
