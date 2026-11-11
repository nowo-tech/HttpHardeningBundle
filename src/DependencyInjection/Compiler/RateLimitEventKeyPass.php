<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\DependencyInjection\Compiler;

use Nowo\HttpHardeningBundle\Event\RateLimitExceededEvent;
use Nowo\HttpHardeningBundle\EventSubscriber\RateLimitSubscriber;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Keys the {@see RateLimitExceededEvent::$clientKey} HMAC with `kernel.secret`
 * (FrameworkBundle's `framework.secret`) when the container has it (MJ-H-L2). Without it the event carries
 * {@see RateLimitSubscriber::UNKEYED_CLIENT_KEY}.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RateLimitEventKeyPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(RateLimitSubscriber::class) && $container->hasParameter('kernel.secret')) {
            $container->getDefinition(RateLimitSubscriber::class)->setArgument('$eventKeySecret', '%kernel.secret%');
        }
    }
}
