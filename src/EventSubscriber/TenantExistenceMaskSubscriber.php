<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\EventSubscriber;

use Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;

/**
 * No existence oracle on tenant URLs.
 *
 * An access denial (`AccessDeniedException` from a voter / `denyAccessUnlessGranted()`, or
 * `AccessDeniedHttpException`) on a path matching one of the configured patterns, for a signed-in user with
 * **no** access at all to the captured tenant ({@see TenantAccessCheckerInterface::hasAnyAccess()}), is replaced
 * by a plain `NotFoundHttpException`: a stranger gets exactly the answer of an unknown tenant id and cannot
 * enumerate which ids exist. Members whose role is too low keep their 403; anonymous visitors are left alone (the
 * firewall still sends them to the login / entry point).
 *
 * The replacement carries **no** `previous` exception: the firewall `ExceptionListener` walks the exception chain
 * and would turn a nested `AccessDeniedException` back into a 403.
 *
 * Registered on `kernel.exception` (default priority 16), before the firewall `ExceptionListener` (priority 1).
 * Stateless (FrankenPHP worker-safe).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class TenantExistenceMaskSubscriber
{
    public const DEFAULT_PRIORITY = 16;

    /** Named capture group holding the tenant id; the first capture group is used when absent. */
    public const TENANT_GROUP = 'tenant';

    /**
     * @param list<string> $pathPatterns PCRE patterns (validated at container compile time) with a `tenant` named
     *                                   group or at least one capture group
     */
    public function __construct(
        private ?TokenStorageInterface $tokenStorage,
        private TenantAccessCheckerInterface $accessChecker,
        private array $pathPatterns,
    ) {
    }

    public function onException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof AccessDeniedException && !$throwable instanceof AccessDeniedHttpException) {
            return;
        }

        $tenantId = $this->tenantId($event->getRequest()->getPathInfo());
        if (null === $tenantId) {
            return;
        }

        $user = $this->tokenStorage?->getToken()?->getUser();
        if (!$user instanceof UserInterface || $this->accessChecker->hasAnyAccess($user, $tenantId)) {
            return;
        }

        // No `previous`: the firewall walks the exception chain and would turn a nested AccessDeniedException
        // back into a 403.
        $event->setThrowable(new NotFoundHttpException());
    }

    /**
     * Tenant id captured by the first matching pattern (`tenant` named group, else group 1), or null.
     */
    public function tenantId(string $path): ?string
    {
        foreach ($this->pathPatterns as $pattern) {
            if (1 !== preg_match($pattern, $path, $match)) {
                continue;
            }
            $tenantId = $match[self::TENANT_GROUP] ?? $match[1] ?? null;
            if (is_string($tenantId) && '' !== $tenantId) {
                return $tenantId;
            }
        }

        return null;
    }
}
