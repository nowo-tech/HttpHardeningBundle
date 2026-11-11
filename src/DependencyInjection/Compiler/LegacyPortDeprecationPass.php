<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\DependencyInjection\Compiler;

use Nowo\HttpHardeningBundle\Csp\CspSourceContributorInterface;
use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\HttpHardeningBundle\EventSubscriber\RateLimitSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\TenantExistenceMaskSubscriber;
use Nowo\HttpHardeningBundle\RateLimit\RateLimitProviderInterface;
use Nowo\HttpHardeningBundle\RateLimit\RuleLimitProviderInterface;
use Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface;
use Nowo\HttpHardeningBundle\Security\TenantAccessQueryInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Reports, once per container build, host services wired to a port deprecated in 1.10.0 (removed in 2.0) that do
 * not implement its framework-free replacement yet. No behaviour change.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegacyPortDeprecationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(TenantExistenceMaskSubscriber::class)) {
            $checker = $container->getDefinition(TenantExistenceMaskSubscriber::class)->getArgument('$accessChecker');
            if ($checker instanceof Reference) {
                $this->report($container, (string) $checker, TenantAccessCheckerInterface::class, TenantAccessQueryInterface::class);
            }
        }

        if ($container->hasDefinition(RateLimitSubscriber::class)) {
            $providers = $container->getDefinition(RateLimitSubscriber::class)->getArgument('$limitProviders');
            foreach (is_array($providers) ? $providers : [] as $provider) {
                if ($provider instanceof Reference) {
                    $this->report($container, (string) $provider, RateLimitProviderInterface::class, RuleLimitProviderInterface::class);
                }
            }
        }

        foreach (array_keys($container->findTaggedServiceIds(CspSourceProviderInterface::TAG)) as $id) {
            $this->report($container, $id, CspSourceProviderInterface::class, CspSourceContributorInterface::class);
        }
    }

    /**
     * @param class-string $legacy
     * @param class-string $replacement
     */
    private function report(ContainerBuilder $container, string $id, string $legacy, string $replacement): void
    {
        $class = $this->serviceClass($container, $id);
        if (null === $class || !is_a($class, $legacy, true) || is_a($class, $replacement, true)) {
            return;
        }

        trigger_deprecation('nowo-tech/http-hardening-bundle', '1.10.0', sprintf(
            'Service "%s" (%s) implements "%s", which is removed in 2.0; implement "%s" instead.',
            $id,
            $class,
            $legacy,
            $replacement,
        ));
    }

    private function serviceClass(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->has($id)) {
            return null;
        }

        // A definition without a class uses its id as the class (as ResolveClassPass does).
        $class = $container->findDefinition($id)->getClass() ?? $id;
        $class = $container->getParameterBag()->resolveValue($class);

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
