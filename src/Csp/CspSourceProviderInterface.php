<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Csp;

use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contributes runtime sources to the Content-Security-Policy (e.g. an image CDN origin that
 * is only known from database settings).
 *
 * Services implementing this interface are autoconfigured with the
 * {@see self::TAG} tag and queried once per HTML response.
 *
 * @deprecated since 1.10.0, implement {@see CspSourceContributorInterface} (takes a framework-free {@see HttpRequestInfo}); removed in 2.0
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface CspSourceProviderInterface
{
    public const TAG = 'nowo_http_hardening.csp_source_provider';

    /**
     * Extra sources keyed by CSP directive name (`img-src`, `connect-src`, …).
     *
     * Unknown directives are ignored; blank sources are skipped.
     *
     * @return array<string, list<string>>
     */
    public function getSources(Request $request): array;
}
