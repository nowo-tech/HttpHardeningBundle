<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Fixtures;

use Nowo\HttpHardeningBundle\Model\UserRef;
use Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface;
use Nowo\HttpHardeningBundle\Security\TenantAccessQueryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A host mid-migration implementing both ports with opposite answers: proves which one the bundle calls.
 */
final readonly class BothPortsTenantAccessChecker implements TenantAccessCheckerInterface, TenantAccessQueryInterface
{
    public function __construct(
        private bool $queryAnswer,
    ) {
    }

    public function hasAnyAccess(UserInterface|UserRef $user, string $tenantId): bool
    {
        return $user instanceof UserRef ? $this->queryAnswer : !$this->queryAnswer;
    }
}
