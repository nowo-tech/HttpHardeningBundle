<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional\App\DddHost;

use Nowo\HttpHardeningBundle\Event\RateLimitExceededEvent;
use Nowo\HttpHardeningBundle\Event\TenantExistenceMaskedEvent;

/**
 * DDD host listener: records the bundle's scalar security events (test double for an audit log).
 */
final class SecurityEventLog
{
    /** @var list<string> */
    public array $entries = [];

    public function onMasked(TenantExistenceMaskedEvent $event): void
    {
        $this->entries[] = 'masked '.$event->tenantId.' '.$event->userIdentifier;
    }

    public function onRateLimited(RateLimitExceededEvent $event): void
    {
        $this->entries[] = 'rate '.$event->rule.' '.$event->limitPerMinute;
    }
}
