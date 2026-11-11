<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Fixtures;

use Nowo\HttpHardeningBundle\Model\UserRef;
use Nowo\HttpHardeningBundle\Security\TenantAccessQueryInterface;

use function in_array;

/**
 * Framework-free test port: same fixed map as {@see RecordingTenantAccessChecker} (user identifier => tenant ids,
 * lower-case), plus an optional role that grants access to every tenant (admin override).
 */
final class MapTenantAccessQuery implements TenantAccessQueryInterface
{
    /** @var list<array{string, string, list<string>}> */
    public array $calls = [];

    /**
     * @param array<string, list<string>> $access
     */
    public function __construct(
        private readonly array $access = [],
        private readonly ?string $overrideRole = null,
    ) {
    }

    public function hasAnyAccess(UserRef $user, string $tenantId): bool
    {
        $this->calls[] = [$user->identifier, $tenantId, $user->roles];

        if (null !== $this->overrideRole && $user->hasRole($this->overrideRole)) {
            return true;
        }

        return in_array(strtolower($tenantId), $this->access[$user->identifier] ?? [], true);
    }
}
