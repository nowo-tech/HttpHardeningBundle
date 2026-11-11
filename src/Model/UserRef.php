<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Model;

use function in_array;

/**
 * Framework-free reference to the signed-in user: the user identifier and the roles the user object reports.
 *
 * Built by the bundle from the security token's user (`UserInterface::getUserIdentifier()` /
 * `UserInterface::getRoles()`), so a host port never needs a Symfony Security type. The roles are the user's own
 * roles, not the role hierarchy expansion.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class UserRef
{
    /** @var list<string> */
    public array $roles;

    /**
     * @param string        $identifier the user identifier (what `UserInterface::getUserIdentifier()` returns)
     * @param array<string> $roles
     */
    public function __construct(
        public string $identifier,
        array $roles = [],
    ) {
        $this->roles = array_values(array_map(strval(...), $roles));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
