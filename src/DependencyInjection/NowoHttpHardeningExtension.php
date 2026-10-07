<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\DependencyInjection;

use Nowo\HttpHardeningBundle\EventSubscriber\AnonymousSessionCookieStripSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\SecurityHeadersSubscriber;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoHttpHardeningExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $headers = $config['security_headers'];
        if ($headers['enabled']) {
            $definition = new Definition(SecurityHeadersSubscriber::class);
            $definition->setAutowired(true);
            $definition->setAutoconfigured(true);
            $definition->setArgument('$xContentTypeOptions', (string) $headers['x_content_type_options']);
            $definition->setArgument('$xFrameOptions', (string) $headers['x_frame_options']);
            $definition->setArgument('$referrerPolicy', (string) $headers['referrer_policy']);
            $definition->setArgument('$permissionsPolicy', (string) $headers['permissions_policy']);
            $definition->setArgument('$crossOriginResourcePolicy', (string) $headers['cross_origin_resource_policy']);
            $definition->setArgument('$hstsEnabled', (bool) $headers['hsts_enabled']);
            $definition->setArgument('$hsts', (string) $headers['hsts']);
            $definition->setArgument('$hstsSkipLoopback', (bool) $headers['hsts_skip_loopback']);
            $definition->addTag('kernel.event_listener', [
                'event' => 'kernel.response',
                'method' => '__invoke',
                'priority' => -80,
            ]);
            $container->setDefinition(SecurityHeadersSubscriber::class, $definition);
        }

        $strip = $config['anonymous_session_strip'];
        if ($strip['enabled']) {
            $definition = new Definition(AnonymousSessionCookieStripSubscriber::class);
            $definition->setAutowired(true);
            $definition->setAutoconfigured(true);
            $definition->setArgument('$tokenStorage', new Reference('security.token_storage'));
            $definition->setArgument('$sessionCookieName', (string) $strip['session_cookie_name']);
            $definition->setArgument('$publicRouteNamePrefixes', array_values($strip['public_route_name_prefixes']));
            $definition->setArgument('$publicRouteNames', array_values($strip['public_route_names']));
            $definition->setArgument('$cacheControl', (string) $strip['cache_control']);
            $definition->addTag('kernel.event_listener', [
                'event' => 'kernel.response',
                'method' => '__invoke',
                'priority' => -1024,
            ]);
            $container->setDefinition(AnonymousSessionCookieStripSubscriber::class, $definition);
        }
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}
