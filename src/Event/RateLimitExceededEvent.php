<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a `rate_limit` rule answered 429 (security observability). Scalars only.
 *
 * `$path` may carry secrets (a token or signed id in a URL segment) and `$clientKey` identifies a client: treat both as
 * sensitive / personal data in logs (retention, access, no forwarding to third parties).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RateLimitExceededEvent extends Event
{
    /**
     * @param string      $rule              the `rate_limit.rules` key
     * @param string      $clientKey         `ip_` + HMAC-SHA256 (truncated to 32 hex) of the client IP keyed with
     *                                       `kernel.secret`: stable per client and secret, not reversible without the
     *                                       secret; `ip_unkeyed` when no secret is configured. Never the raw IP, and
     *                                       not the cache bucket key
     * @param int         $limitPerMinute    the limit that was applied
     * @param string      $path              percent-decoded path the router matches (sensitive, see above)
     * @param int         $retryAfterSeconds the `Retry-After` value sent
     * @param string|null $routeName         matched route name when the router already ran (usually null: the rule
     *                                       runs before routing); prefer it over `$path` in logs
     */
    public function __construct(
        public readonly string $rule,
        public readonly string $clientKey,
        public readonly int $limitPerMinute,
        public readonly string $path,
        public readonly int $retryAfterSeconds,
        public readonly ?string $routeName = null,
    ) {
    }
}
