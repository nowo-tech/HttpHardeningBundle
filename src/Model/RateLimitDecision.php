<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Model;

use DateTimeImmutable;

/**
 * Framework-free result of consuming one token from a per-minute limiter.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class RateLimitDecision
{
    public function __construct(
        public bool $accepted,
        public int $limit,
        public int $remaining,
        public DateTimeImmutable $retryAfter,
    ) {
    }
}
