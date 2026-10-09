<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Http;

/**
 * Sanitises href attributes: relative paths and http(s) URLs only.
 *
 * Rejects `javascript:`, `data:`, `vbscript:`, protocol-relative URLs, backslashes and control
 * characters.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SafeHref
{
    /**
     * Returns a safe href or null when the value must not be rendered as a link.
     */
    public static function resolve(?string $href): ?string
    {
        $href = trim((string) $href);
        if ('' === $href || 1 === preg_match('/[\x00-\x1f\x7f]/', $href)) {
            return null;
        }

        $lower = strtolower($href);
        if (str_starts_with($lower, 'javascript:')
            || str_starts_with($lower, 'data:')
            || str_starts_with($lower, 'vbscript:')
            || str_starts_with($href, '//')
            || str_contains($href, '\\')) {
            return null;
        }

        if (str_starts_with($href, '/') || 1 === preg_match('#^https?://#i', $href)) {
            return $href;
        }

        // Bare relative paths used in editorial payloads ("contact", "docs/intro").
        if (1 === preg_match('/^[a-z0-9][a-z0-9_.\/?#&=%-]*$/i', $href)) {
            return $href;
        }

        return null;
    }
}
