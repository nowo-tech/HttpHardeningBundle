<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpHardeningBundle\DependencyInjection\NowoHttpHardeningExtension;
use Nowo\HttpHardeningBundle\EventSubscriber\AnonymousSessionCookieStripSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoHttpHardeningExtensionTest extends TestCase
{
    public function testLoadRegistersSecurityHeadersByDefault(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([], $container);

        self::assertTrue($container->hasDefinition(SecurityHeadersSubscriber::class));
        self::assertFalse($container->hasDefinition(AnonymousSessionCookieStripSubscriber::class));
        self::assertSame('nowo_http_hardening', (new NowoHttpHardeningExtension())->getAlias());
    }

    public function testLoadCanDisableSecurityHeaders(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([
            [
                'security_headers' => ['enabled' => false],
            ],
        ], $container);

        self::assertFalse($container->hasDefinition(SecurityHeadersSubscriber::class));
    }

    public function testLoadRegistersAnonymousSessionStripWhenEnabled(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([
            [
                'anonymous_session_strip' => [
                    'enabled' => true,
                    'session_cookie_name' => 'APPSESSID',
                    'public_route_name_prefixes' => ['site_'],
                    'public_route_names' => ['nowo_seo_kit_robots'],
                    'cache_control' => 'public, max-age=60',
                ],
            ],
        ], $container);

        self::assertTrue($container->hasDefinition(AnonymousSessionCookieStripSubscriber::class));
        $definition = $container->getDefinition(AnonymousSessionCookieStripSubscriber::class);
        self::assertSame('APPSESSID', $definition->getArgument('$sessionCookieName'));
        self::assertSame(['site_'], $definition->getArgument('$publicRouteNamePrefixes'));
        self::assertSame(['nowo_seo_kit_robots'], $definition->getArgument('$publicRouteNames'));
        self::assertSame('public, max-age=60', $definition->getArgument('$cacheControl'));
    }
}
