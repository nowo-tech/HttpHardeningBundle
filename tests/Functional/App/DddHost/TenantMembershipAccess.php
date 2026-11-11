<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional\App\DddHost;

use Nowo\HttpHardeningBundle\Model\UserRef;
use Nowo\HttpHardeningBundle\Security\TenantAccessQueryInterface;

use function in_array;
use function strtolower;

/**
 * DDD host adapter (infrastructure layer): answers the bundle's framework-free port from an application read model,
 * by user identifier. No Symfony type anywhere in the host code.
 */
final readonly class TenantMembershipAccess implements TenantAccessQueryInterface
{
    /**
     * @param array<string, list<string>> $memberships user identifier => tenant ids (lower-case)
     */
    public function __construct(
        private array $memberships,
    ) {
    }

    public function hasAnyAccess(UserRef $user, string $tenantId): bool
    {
        return in_array(strtolower($tenantId), $this->memberships[$user->identifier] ?? [], true);
    }
}
