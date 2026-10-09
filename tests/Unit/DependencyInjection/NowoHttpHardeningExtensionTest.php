<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpHardeningBundle\Command\LintCspNoncesCommand;
use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\HttpHardeningBundle\Csp\InlineNonceLinter;
use Nowo\HttpHardeningBundle\DependencyInjection\NowoHttpHardeningExtension;
use Nowo\HttpHardeningBundle\EventSubscriber\AnonymousSessionCookieStripSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\CanonicalHostRedirectSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\ContentSecurityPolicySubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\PublicCacheControlSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\SecurityHeadersSubscriber;
use Nowo\HttpHardeningBundle\Http\SafeInternalRedirect;
use Nowo\HttpHardeningBundle\Twig\CspNonceExtension;
use Nowo\HttpHardeningBundle\Twig\SafeUrlExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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

    public function testNewFeaturesAreOffByDefault(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([], $container);

        self::assertFalse($container->hasDefinition(ContentSecurityPolicySubscriber::class));
        self::assertFalse($container->hasDefinition(CspNonceExtension::class));
        self::assertFalse($container->hasDefinition(PublicCacheControlSubscriber::class));
        self::assertFalse($container->hasDefinition(CanonicalHostRedirectSubscriber::class));
        self::assertFalse($container->hasDefinition(SafeUrlExtension::class));
        // Stateless helpers are always available.
        self::assertTrue($container->hasDefinition(SafeInternalRedirect::class));
        self::assertTrue($container->hasDefinition(InlineNonceLinter::class));
        self::assertTrue($container->getDefinition(LintCspNoncesCommand::class)->hasTag('console.command'));
        self::assertSame('%kernel.project_dir%', $container->getDefinition(LintCspNoncesCommand::class)->getArgument('$projectDir'));
        self::assertArrayHasKey(CspSourceProviderInterface::class, $container->getAutoconfiguredInstanceof());
    }

    public function testLoadRegistersCspWhenEnabled(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([
            [
                'csp' => [
                    'enabled' => true,
                    'report_only' => true,
                    'stamp_nonces' => false,
                    'debug_relaxations' => false,
                    'img_src_extra' => ['https://cdn.example'],
                    'frame_src' => [],
                    'excluded_path_prefixes' => ['/_wdt'],
                ],
            ],
        ], $container);

        $definition = $container->getDefinition(ContentSecurityPolicySubscriber::class);
        self::assertSame('%kernel.debug%', $definition->getArgument('$kernelDebug'));
        $directives = $definition->getArgument('$directives');
        self::assertIsArray($directives);
        self::assertSame(array_keys(ContentSecurityPolicySubscriber::DEFAULT_DIRECTIVES), array_keys($directives));
        self::assertSame([], $directives['frame-src']);
        self::assertSame(["'self'", 'data:', 'blob:'], $directives['img-src']);
        $extras = $definition->getArgument('$extraSources');
        self::assertIsArray($extras);
        self::assertSame(['https://cdn.example'], $extras['img-src']);
        self::assertInstanceOf(TaggedIteratorArgument::class, $definition->getArgument('$sourceProviders'));
        self::assertSame(['/_wdt'], $definition->getArgument('$excludedPathPrefixes'));
        self::assertFalse($definition->getArgument('$stampNonces'));
        self::assertFalse($definition->getArgument('$debugRelaxations'));
        self::assertTrue($definition->getArgument('$reportOnly'));

        $tags = $definition->getTag('kernel.event_listener');
        self::assertSame(['kernel.request', 'onRequest', 1024], [$tags[0]['event'], $tags[0]['method'], $tags[0]['priority']]);
        self::assertSame(['kernel.response', 'onResponse', -100], [$tags[1]['event'], $tags[1]['method'], $tags[1]['priority']]);
        self::assertTrue($container->getDefinition(CspNonceExtension::class)->hasTag('twig.extension'));
    }

    public function testLoadRegistersPublicCacheAfterStrip(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([
            [
                'anonymous_session_strip' => ['enabled' => true],
                'public_cache' => [
                    'enabled' => true,
                    'page_route_name_prefixes' => ['site_'],
                    'page_route_names' => ['home'],
                    'file_route_names' => ['nowo_seo_kit_sitemap'],
                    'session_cookie_name' => 'APPSESSID',
                    'remember_me_cookie_name' => '',
                ],
            ],
        ], $container);

        $definition = $container->getDefinition(PublicCacheControlSubscriber::class);
        self::assertSame(['site_'], $definition->getArgument('$pageRouteNamePrefixes'));
        self::assertSame(['home'], $definition->getArgument('$pageRouteNames'));
        self::assertSame(PublicCacheControlSubscriber::DEFAULT_PAGE_CACHE_CONTROL, $definition->getArgument('$pageCacheControl'));
        self::assertSame(['nowo_seo_kit_sitemap'], $definition->getArgument('$fileRouteNames'));
        self::assertSame(PublicCacheControlSubscriber::DEFAULT_FILE_CACHE_CONTROL, $definition->getArgument('$fileCacheControl'));
        self::assertSame('APPSESSID', $definition->getArgument('$sessionCookieName'));
        self::assertSame('', $definition->getArgument('$rememberMeCookieName'));

        $cachePriority = $definition->getTag('kernel.event_listener')[0]['priority'];
        $stripPriority = $container->getDefinition(AnonymousSessionCookieStripSubscriber::class)->getTag('kernel.event_listener')[0]['priority'];
        self::assertLessThan($stripPriority, $cachePriority, 'Public cache must run after the strip subscriber.');
    }

    public function testLoadRegistersCanonicalHostAndSafeUrlTwigFunctions(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([
            [
                'canonical_host' => ['enabled' => true, 'base_url' => 'https://example.com', 'status_code' => 308],
                'safe_urls' => ['twig_functions' => true],
            ],
        ], $container);

        $definition = $container->getDefinition(CanonicalHostRedirectSubscriber::class);
        self::assertSame('https://example.com', $definition->getArgument('$publicBaseUrl'));
        self::assertSame(308, $definition->getArgument('$statusCode'));
        self::assertSame(2048, $definition->getTag('kernel.event_listener')[0]['priority']);
        self::assertTrue($container->getDefinition(SafeUrlExtension::class)->hasTag('twig.extension'));
    }

    public function testCompiledContainerWiresCspProvidersAndHelpers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->register('request_stack', RequestStack::class)->setSynthetic(true);
        $container->register('app.cdn', StubCspSourceProvider::class)->setAutoconfigured(true);
        (new NowoHttpHardeningExtension())->load([['csp' => ['enabled' => true]]], $container);
        $container->getDefinition(ContentSecurityPolicySubscriber::class)->setPublic(true);
        $container->getDefinition(SafeInternalRedirect::class)->setPublic(true);
        $container->compile();
        $container->set('request_stack', new RequestStack());

        $subscriber = $container->get(ContentSecurityPolicySubscriber::class);
        self::assertInstanceOf(ContentSecurityPolicySubscriber::class, $subscriber);
        self::assertStringContainsString('https://ik.imagekit.io', $subscriber->buildPolicy(Request::create('/'), ''));
        self::assertInstanceOf(SafeInternalRedirect::class, $container->get(SafeInternalRedirect::class));

        $request = Request::create('/');
        $subscriber->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertTrue($request->attributes->has('csp_nonce'));
    }
}

/**
 * @internal
 */
final class StubCspSourceProvider implements CspSourceProviderInterface
{
    public function getSources(Request $request): array
    {
        return ['img-src' => ['https://ik.imagekit.io']];
    }
}
