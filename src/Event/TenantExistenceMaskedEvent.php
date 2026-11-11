<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after `tenant_existence_mask` replaced an access denial with a 404 (security observability).
 * Scalars only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TenantExistenceMaskedEvent extends Event
{
    /**
     * @param string $tenantId       the raw tenant capture
     * @param string $path           percent-decoded path the router matches
     * @param string $userIdentifier the signed-in user's identifier
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $path,
        public readonly string $userIdentifier,
    ) {
    }
}
