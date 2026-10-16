<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host SPI for the `tenant_existence_mask` feature: does a signed-in user have **any** access to a tenant?
 *
 * "Any" means the lowest level that lets the user know the tenant exists (member, viewer, share grant, admin
 * override…), not the role a given page requires. When this returns `false` for a tenant id captured from a
 * masked path, an access denial on that path answers 404 — the same answer as an unknown tenant. When it returns
 * `true`, the 403 is kept (the user already knows the tenant).
 *
 * Return `false` for an unknown or malformed id too. Implementations must be stateless (FrankenPHP worker mode):
 * no per-request memoisation in properties.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface TenantAccessCheckerInterface
{
    /**
     * @param string $tenantId the raw capture from the matched path pattern (normalise case yourself if needed)
     */
    public function hasAnyAccess(UserInterface $user, string $tenantId): bool;
}
