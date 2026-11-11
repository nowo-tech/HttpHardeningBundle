<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Fixtures;

use Nowo\HttpHardeningBundle\Csp\CspSourceContributorInterface;
use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;
use Symfony\Component\HttpFoundation\Request;

/**
 * A CSP source provider mid-migration: both ports.
 */
final class BothCspProvider implements CspSourceProviderInterface, CspSourceContributorInterface
{
    public function getSources(Request|HttpRequestInfo $request): array
    {
        return [];
    }
}
