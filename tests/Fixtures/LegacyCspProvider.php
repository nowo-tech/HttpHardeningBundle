<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Fixtures;

use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A CSP source provider on the deprecated port only.
 */
final class LegacyCspProvider implements CspSourceProviderInterface
{
    public function getSources(Request $request): array
    {
        return [];
    }
}
