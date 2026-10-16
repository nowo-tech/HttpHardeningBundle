<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional\App;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Every action denies: like a voter attribute (`AccessDeniedException`, what `denyAccessUnlessGranted()` throws)
 * or an access guard (`AccessDeniedHttpException`); `notFound` mimics an unknown tenant id.
 */
final class TenantMaskController
{
    public function voterDenied(): Response
    {
        throw new AccessDeniedException('Requires ROLE_ADMIN.');
    }

    public function guardDenied(): Response
    {
        throw new AccessDeniedHttpException('Too low a role.');
    }

    public function notFound(): Response
    {
        throw new NotFoundHttpException();
    }
}
