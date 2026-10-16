<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Fixtures;

use Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * Test checker: fixed map of user identifier => tenant ids (lower-case) the user has some access to.
 */
final class RecordingTenantAccessChecker implements TenantAccessCheckerInterface
{
    /** @var list<array{string, string}> */
    public array $calls = [];

    /**
     * @param array<string, list<string>> $access
     */
    public function __construct(private readonly array $access = [])
    {
    }

    public function hasAnyAccess(UserInterface $user, string $tenantId): bool
    {
        $this->calls[] = [$user->getUserIdentifier(), $tenantId];

        return in_array(strtolower($tenantId), $this->access[$user->getUserIdentifier()] ?? [], true);
    }
}
