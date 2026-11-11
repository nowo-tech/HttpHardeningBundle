<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional\App\DddHost;

use Nowo\HttpHardeningBundle\Csp\CspSourceContributorInterface;
use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;

/**
 * DDD host adapter: a CDN origin from settings, autoconfigured as a CSP source contributor.
 */
final readonly class CdnCspSources implements CspSourceContributorInterface
{
    public function getSources(HttpRequestInfo $request): array
    {
        return ['img-src' => ['https://cdn.ddd-host.example']];
    }
}
