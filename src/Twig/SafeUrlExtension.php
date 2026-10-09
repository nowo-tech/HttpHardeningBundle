<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Twig;

use Nowo\HttpHardeningBundle\Http\SafeHref;
use Nowo\HttpHardeningBundle\Http\SafeInternalRedirect;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `safe_internal_path(target, fallback = '/')` and `safe_href(href)` (open-redirect /
 * dangerous-scheme hardening for Twig).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SafeUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly SafeInternalRedirect $safeInternalRedirect,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('safe_internal_path', $this->safeInternalRedirect->resolveForCurrentRequest(...)),
            new TwigFunction('safe_href', SafeHref::resolve(...)),
        ];
    }
}
