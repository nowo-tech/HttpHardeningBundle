<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Http;

/**
 * Open-redirect guard (autowirable; implemented by {@see SafeInternalRedirect}).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SafeInternalRedirectInterface
{
    /**
     * A safe relative path for $target against the current request; $fallback otherwise or without a request.
     */
    public function resolveForCurrentRequest(string $target, string $fallback = '/'): string;

    /**
     * A safe relative path for $target against an explicit origin (`https://example.com`, as
     * `Request::getSchemeAndHttpHost()` returns it); $fallback otherwise.
     *
     * Absolute URLs on that origin are reduced to their path + query. Protocol-relative URLs, other origins,
     * backslashes, encoded separators and control characters fall back.
     */
    public function resolveForOrigin(string $schemeAndHttpHost, string $target, string $fallback = '/'): string;
}
