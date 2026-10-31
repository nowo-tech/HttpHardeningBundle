<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\RateLimit;

use Symfony\Component\HttpFoundation\Request;

/**
 * Runtime limit for a `rate_limit.rules` entry (a setting stored in the database, an environment-specific quota).
 *
 * Called on every matching main request: keep it cheap (cached settings) and stateless (FrankenPHP worker mode).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface RateLimitProviderInterface
{
    /**
     * @param string  $rule    the `rate_limit.rules` key being evaluated
     * @param Request $request the matching main request
     *
     * @return int requests allowed per sliding minute for this client; `0` or less lets the request through
     */
    public function limitPerMinute(string $rule, Request $request): int;
}
