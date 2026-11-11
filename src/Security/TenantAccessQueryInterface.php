<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Security;

use Nowo\HttpHardeningBundle\Model\UserRef;

/**
 * Host port for the `tenant_existence_mask` feature, framework-free: does a signed-in user have **any** access to a
 * tenant?
 *
 * Same contract as the deprecated {@see TenantAccessCheckerInterface}, with a {@see UserRef} (identifier + roles)
 * instead of a Symfony `UserInterface`. "Any" means the lowest level that lets the user know the tenant exists
 * (member, viewer, share grant, admin override…). Returning `false` masks the access denial as a 404 (the answer
 * of an unknown tenant); `true` keeps the 403.
 *
 * Return `false` for an unknown or malformed id too (deny by default). Implementations must be stateless
 * (FrankenPHP worker mode): no per-request memoisation in properties.
 *
 * When the container has both this port and the deprecated one, this port wins.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface TenantAccessQueryInterface
{
    /**
     * @param string $tenantId the raw capture from the matched path pattern (normalise case yourself if needed)
     */
    public function hasAnyAccess(UserRef $user, string $tenantId): bool;
}
