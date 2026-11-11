<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a `rate_limit` rule answered 429 (security observability). Scalars only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RateLimitExceededEvent extends Event
{
    /**
     * @param string $rule              the `rate_limit.rules` key
     * @param string $clientKey         hashed client bucket (`ip_<xxh128>`); never the raw IP
     * @param int    $limitPerMinute    the limit that was applied
     * @param string $path              percent-decoded path the router matches
     * @param int    $retryAfterSeconds the `Retry-After` value sent
     */
    public function __construct(
        public readonly string $rule,
        public readonly string $clientKey,
        public readonly int $limitPerMinute,
        public readonly string $path,
        public readonly int $retryAfterSeconds,
    ) {
    }
}
