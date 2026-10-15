<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpHardeningBundle\Command\LintCspNoncesCommand;
use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\HttpHardeningBundle\Csp\InlineNonceLinter;
use Nowo\HttpHardeningBundle\DependencyInjection\Compiler\WebProfilerCspHandlerPass;
use Nowo\HttpHardeningBundle\DependencyInjection\NowoHttpHardeningExtension;
use Nowo\HttpHardeningBundle\EventSubscriber\AnonymousSessionCookieStripSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\CanonicalHostRedirectSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\ContentSecurityPolicySubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\NoStoreSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\PublicCacheControlSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\SecurityHeadersSubscriber;
use Nowo\HttpHardeningBundle\Http\SafeInternalRedirect;
use Nowo\HttpHardeningBundle\Http\TrustedHostsEnvVarProcessor;
use Nowo\HttpHardeningBundle\Twig\CspNonceExtension;
use Nowo\HttpHardeningBundle\Twig\SafeUrlExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

use function is_array;

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
                    'nonce_free_style_path_prefixes' => ['/admin/legal'],
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
        self::assertSame(['/admin/legal'], $definition->getArgument('$nonceFreeStylePathPrefixes'));

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

    public function testNoStoreIsOffByDefault(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([['no_store' => ['path_patterns' => ['#^/admin#']]]], $container);

        self::assertFalse($container->hasDefinition(NoStoreSubscriber::class));
    }

    public function testLoadRegistersNoStoreWhenEnabled(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([[
            'no_store' => ['enabled' => true, 'path_patterns' => ['#^/admin(?:/|$)#'], 'routes' => ['app_keys']],
        ]], $container);

        $definition = $container->getDefinition(NoStoreSubscriber::class);
        self::assertSame(['#^/admin(?:/|$)#'], $definition->getArgument('$pathPatterns'));
        self::assertSame(['app_keys'], $definition->getArgument('$routes'));
        self::assertSame([['event' => 'kernel.response', 'method' => '__invoke', 'priority' => -1200]], $definition->getTag('kernel.event_listener'));
    }

    public function testInvalidNoStorePatternFailsContainerBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('no_store.path_patterns');

        (new NowoHttpHardeningExtension())->load([['no_store' => ['enabled' => true, 'path_patterns' => ['#(#']]]], new ContainerBuilder());
    }

    public function testLoadRegistersTheTrustedHostsEnvVarProcessor(): void
    {
        $bare = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([[]], $bare);
        self::assertSame('prod', $bare->getDefinition(TrustedHostsEnvVarProcessor::class)->getArgument('$environment'));

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'prod');
        (new NowoHttpHardeningExtension())->load([['trusted_hosts' => ['internal_hosts' => ['app-php']]]], $container);

        $definition = $container->getDefinition(TrustedHostsEnvVarProcessor::class);
        self::assertTrue($definition->hasTag('container.env_var_processor'));
        self::assertSame('%kernel.environment%', $definition->getArgument('$environment'));
        self::assertSame('TRUSTED_HOSTS', $definition->getArgument('$fallbackEnv'));
        self::assertSame('DEFAULT_URI', $definition->getArgument('$publicUrlEnv'));
        self::assertSame(['app-php'], $definition->getArgument('$internalHosts'));
        self::assertSame(['dev', 'test'], $definition->getArgument('$localEnvironments'));
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

    public function testEachResponseListenerIsRegisteredExactlyOnce(): void
    {
        $container = new ContainerBuilder();
        // Same attribute autoconfiguration FrameworkBundle performs for #[AsEventListener].
        $container->registerAttributeForAutoconfiguration(AsEventListener::class, static function (ChildDefinition $definition, AsEventListener $attribute): void {
            $definition->addTag('kernel.event_listener', get_object_vars($attribute));
        });
        $container->register('event_dispatcher', EventDispatcher::class)->setPublic(true);
        $container->register('security.token_storage', TokenStorage::class);
        (new NowoHttpHardeningExtension())->load([[
            'anonymous_session_strip' => ['enabled' => true, 'public_route_names' => ['home']],
            'public_cache' => ['enabled' => true, 'page_route_names' => ['home']],
            'no_store' => ['enabled' => true, 'path_patterns' => ['#^/admin#']],
        ]], $container);
        foreach ([SecurityHeadersSubscriber::class, AnonymousSessionCookieStripSubscriber::class, PublicCacheControlSubscriber::class, NoStoreSubscriber::class] as $id) {
            // Worst case: an app-level `_defaults: autoconfigure: true` reaching the bundle services.
            $container->getDefinition($id)->setAutoconfigured(true);
        }
        $container->addCompilerPass(new RegisterListenersPass());
        $container->compile();

        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcher::class, $dispatcher);
        $counts = [];
        foreach ($dispatcher->getListeners('kernel.response') as $listener) {
            $object = is_array($listener) ? $listener[0] : $listener;
            self::assertIsObject($object);
            $counts[$object::class] = ($counts[$object::class] ?? 0) + 1;
        }

        self::assertSame(1, $counts[SecurityHeadersSubscriber::class] ?? 0);
        self::assertSame(1, $counts[AnonymousSessionCookieStripSubscriber::class] ?? 0);
        self::assertSame(1, $counts[PublicCacheControlSubscriber::class] ?? 0);
        self::assertSame(1, $counts[NoStoreSubscriber::class] ?? 0);
        self::assertSame(-80, $dispatcher->getListenerPriority('kernel.response', $this->findListener($dispatcher, SecurityHeadersSubscriber::class)));
        self::assertSame(-1024, $dispatcher->getListenerPriority('kernel.response', $this->findListener($dispatcher, AnonymousSessionCookieStripSubscriber::class)));
        self::assertSame(-1200, $dispatcher->getListenerPriority('kernel.response', $this->findListener($dispatcher, NoStoreSubscriber::class)));
        self::assertLessThan(
            $dispatcher->getListenerPriority('kernel.response', $this->findListener($dispatcher, PublicCacheControlSubscriber::class)),
            $dispatcher->getListenerPriority('kernel.response', $this->findListener($dispatcher, NoStoreSubscriber::class)),
            'no-store must run after public cache.',
        );
        self::assertCount(4, $counts);
    }

    public function testContainerCompilesWithoutSecurityBundle(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([[
            'anonymous_session_strip' => ['enabled' => true, 'session_cookie_name' => 'PHPSESSID', 'public_route_names' => ['home']],
            'public_cache' => ['enabled' => true, 'page_route_names' => ['home'], 'page_cache_control' => 'public, max-age=60'],
        ]], $container);
        self::assertFalse($container->has('security.token_storage'));
        $container->getDefinition(AnonymousSessionCookieStripSubscriber::class)->setPublic(true);
        $container->getDefinition(PublicCacheControlSubscriber::class)->setPublic(true);
        $container->compile();

        $strip = $container->get(AnonymousSessionCookieStripSubscriber::class);
        $cache = $container->get(PublicCacheControlSubscriber::class);
        self::assertInstanceOf(AnonymousSessionCookieStripSubscriber::class, $strip);
        self::assertInstanceOf(PublicCacheControlSubscriber::class, $cache);

        $request = Request::create('/');
        $request->attributes->set('_route', 'home');
        $response = new Response('ok');
        $response->headers->setCookie(Cookie::create('PHPSESSID', 'abc'));
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $strip($event);
        $cache($event);

        self::assertSame([], $response->headers->getCookies());
        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
    }

    public function testLoadExposesWebProfilerIsolationSwitch(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([], $container);
        self::assertTrue($container->getParameter(WebProfilerCspHandlerPass::ENABLED_PARAMETER));

        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([['web_profiler' => ['isolate_csp_handler' => false]]], $container);
        self::assertFalse($container->getParameter(WebProfilerCspHandlerPass::ENABLED_PARAMETER));
    }

    /**
     * @param class-string $class
     *
     * @return callable|array<mixed>
     */
    private function findListener(EventDispatcher $dispatcher, string $class): callable|array
    {
        foreach ($dispatcher->getListeners('kernel.response') as $listener) {
            if ((is_array($listener) ? $listener[0] : $listener) instanceof $class) {
                return $listener;
            }
        }

        self::fail('Listener '.$class.' not registered.');
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
