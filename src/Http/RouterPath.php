<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Http;

use Symfony\Component\HttpFoundation\Request;

use function rawurldecode;

/**
 * The path the Symfony router matches: `rawurldecode(Request::getPathInfo())`.
 *
 * `UrlMatcher` / `CompiledUrlMatcher` decode the path info exactly once before matching, so `/%73ecrets/abc` and
 * `/secrets%2Fabc` reach the route of `/secrets/abc`. A path check on the raw `getPathInfo()` would let a
 * percent-encoded byte skip it while the router still dispatches to the protected route. Decoding exactly once (like
 * the router) keeps both views equal: `/%2573ecrets` decodes to `/%73ecrets`, which the router does not match as
 * `/secrets` either.
 *
 * Stateless: static helpers only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RouterPath
{
    public static function of(Request $request): string
    {
        // getPathInfo() always starts with "/", so the decoded path is never empty.
        return rawurldecode($request->getPathInfo());
    }
}
