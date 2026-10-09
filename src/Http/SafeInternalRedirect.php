<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function strlen;

/**
 * Rejects open redirects while allowing same-app relative paths (e.g. `?_target_path=`,
 * `?redirect=` query parameters).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SafeInternalRedirect
{
    public function __construct(
        private ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * Returns a safe relative path; otherwise $fallback.
     *
     * Absolute same-host URLs are reduced to their path+query. Protocol-relative URLs,
     * backslashes, encoded separators, and control characters are rejected.
     */
    public static function resolve(Request $request, string $target, string $fallback): string
    {
        $target = trim($target);
        if ('' === $target || 1 === preg_match('/[\x00-\x1f\x7f]/', $target)) {
            return $fallback;
        }

        $host = $request->getSchemeAndHttpHost();
        if (str_starts_with($target, $host.'/')) {
            $target = substr($target, strlen($host));
        }

        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return $fallback;
        }

        if (str_contains($target, '\\') || str_contains(strtolower($target), '%5c')) {
            return $fallback;
        }

        // Reject "/%2F%2Fevil" and similar after decoding one level of common encodings.
        $decoded = rawurldecode($target);
        if ($decoded !== $target && (str_contains($decoded, '\\') || str_starts_with($decoded, '//'))) {
            return $fallback;
        }

        return $target;
    }

    /**
     * Same as {@see resolve()} against the current request; $fallback when there is none.
     */
    public function resolveForCurrentRequest(string $target, string $fallback = '/'): string
    {
        $request = $this->requestStack?->getCurrentRequest();
        if (!$request instanceof Request) {
            return $fallback;
        }

        return self::resolve($request, $target, $fallback);
    }
}
