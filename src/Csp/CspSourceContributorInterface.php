<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Csp;

use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;

/**
 * Contributes runtime sources to the Content-Security-Policy, framework-free (e.g. an image CDN origin that is only
 * known from database settings).
 *
 * Same contract as the deprecated {@see CspSourceProviderInterface}, with an {@see HttpRequestInfo} instead of a
 * Symfony `Request`. Implementations are autoconfigured with the {@see self::CONTRIBUTOR_TAG} tag and queried once per HTML
 * response.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface CspSourceContributorInterface
{
    /** Not `TAG`: a class implementing both ports during the migration would inherit two `TAG` constants. */
    public const CONTRIBUTOR_TAG = 'nowo_http_hardening.csp_source_contributor';

    /**
     * Extra sources keyed by CSP directive name (`img-src`, `connect-src`, …).
     *
     * Unknown directives are ignored; blank sources are skipped.
     *
     * @return array<string, list<string>>
     */
    public function getSources(HttpRequestInfo $request): array;
}
