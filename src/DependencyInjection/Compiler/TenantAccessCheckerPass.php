<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\DependencyInjection\Compiler;

use Nowo\HttpHardeningBundle\EventSubscriber\TenantExistenceMaskSubscriber;
use Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface;
use Nowo\HttpHardeningBundle\Security\TenantAccessQueryInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Default `tenant_existence_mask.access_checker` (unset in config): the framework-free
 * {@see TenantAccessQueryInterface} when the host registered it, else the deprecated
 * {@see TenantAccessCheckerInterface} alias.
 *
 * Neither registered keeps the reference to the deprecated interface, which fails the container build: the mask
 * never runs without a host decision (fail closed, as before 1.10.0). An explicit `access_checker` id is left as is.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TenantAccessCheckerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TenantExistenceMaskSubscriber::class)) {
            return;
        }

        $definition = $container->getDefinition(TenantExistenceMaskSubscriber::class);
        $current = $definition->getArgument('$accessChecker');
        if (!$current instanceof Reference || TenantAccessCheckerInterface::class !== (string) $current) {
            return;
        }

        if ($container->has(TenantAccessQueryInterface::class)) {
            $definition->setArgument('$accessChecker', new Reference(TenantAccessQueryInterface::class));
        }
    }
}
