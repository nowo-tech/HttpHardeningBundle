<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\RateLimit;

use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;

/**
 * Runtime limit for a `rate_limit.rules` entry, framework-free (a setting stored in the database, a plan quota).
 *
 * Same contract as the deprecated {@see RateLimitProviderInterface}, with an {@see HttpRequestInfo} instead of a
 * Symfony `Request`. Called on every matching main request: keep it cheap (cached settings) and stateless
 * (FrankenPHP worker mode).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface RuleLimitProviderInterface
{
    /**
     * @param string          $rule    the `rate_limit.rules` key being evaluated
     * @param HttpRequestInfo $request the matching main request
     *
     * @return int requests allowed per sliding minute for this client; `0` or less lets the request through
     */
    public function limitPerMinute(string $rule, HttpRequestInfo $request): int;
}
