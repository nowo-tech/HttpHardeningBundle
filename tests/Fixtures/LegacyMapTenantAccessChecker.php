<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Fixtures;

use Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * Deprecated-port twin of {@see MapTenantAccessQuery}: the same decision written against `UserInterface`, as a
 * standard host wrote it before 1.10.0 (equivalence tests).
 */
final readonly class LegacyMapTenantAccessChecker implements TenantAccessCheckerInterface
{
    /**
     * @param array<string, list<string>> $access
     */
    public function __construct(
        private array $access = [],
        private ?string $overrideRole = null,
    ) {
    }

    public function hasAnyAccess(UserInterface $user, string $tenantId): bool
    {
        if (null !== $this->overrideRole && in_array($this->overrideRole, $user->getRoles(), true)) {
            return true;
        }

        return in_array(strtolower($tenantId), $this->access[$user->getUserIdentifier()] ?? [], true);
    }
}
