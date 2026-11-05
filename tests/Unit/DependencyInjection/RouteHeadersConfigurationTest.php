<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpHardeningBundle\DependencyInjection\Configuration;
use Nowo\HttpHardeningBundle\DependencyInjection\NowoHttpHardeningExtension;
use Nowo\HttpHardeningBundle\EventSubscriber\ContentSecurityPolicySubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\NoStoreSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\RouteHeadersSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function is_array;
use function is_string;

final class RouteHeadersConfigurationTest extends TestCase
{
    public function testOffByDefault(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([], $container);

        self::assertFalse($container->hasDefinition(RouteHeadersSubscriber::class));
        self::assertSame([], $this->process([])['route_headers']);
    }

    public function testNormalisesScalarsAndDefaults(): void
    {
        $config = $this->process([
            'route_headers' => [
                ['match' => ['route_prefix' => 'secret_'], 'frame' => 'deny', 'referrer' => 'no-referrer', 'robots' => 'noindex', 'no_store' => true],
                ['match' => ['route' => ['a', 'b'], 'path_prefix' => '/device'], 'frame' => 'sameorigin'],
            ],
        ]);

        self::assertSame([
            [
                'match' => ['route_prefix' => ['secret_'], 'route' => [], 'path_prefix' => []],
                'frame' => 'deny',
                'referrer' => 'no-referrer',
                'robots' => 'noindex',
                'no_store' => true,
            ],
            [
                'match' => ['route' => ['a', 'b'], 'path_prefix' => ['/device'], 'route_prefix' => []],
                'frame' => 'sameorigin',
                'referrer' => null,
                'robots' => null,
                'no_store' => false,
            ],
        ], $config['route_headers']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'no match' => [['frame' => 'deny'], 'match'];
        yield 'empty match' => [['match' => [], 'frame' => 'deny'], 'at least one of route, route_prefix or path_prefix'];
        yield 'nothing to set' => [['match' => ['route' => 'x']], 'at least one of frame, referrer, robots or no_store'];
        yield 'unknown frame' => [['match' => ['route' => 'x'], 'frame' => 'allow-from'], 'frame must be'];
        yield 'unknown referrer' => [['match' => ['route' => 'x'], 'referrer' => 'none'], 'not a Referrer-Policy value'];
        yield 'multi-line robots' => [['match' => ['route' => 'x'], 'robots' => "noindex\r\nX-Evil: 1"], 'single-line'];
        yield 'empty robots' => [['match' => ['route' => 'x'], 'robots' => ' '], 'single-line'];
        yield 'empty route prefix' => [['match' => ['route_prefix' => ''], 'robots' => 'noindex'], 'route_prefix'];
    }

    /**
     * @param array<string, mixed> $rule
     */
    #[DataProvider('invalidRules')]
    public function testInvalidRules(array $rule, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process(['route_headers' => [$rule]]);
    }

    public function testRunsAfterTheCspNonceListenerAndNoStore(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->register('event_dispatcher', EventDispatcher::class)->setPublic(true);
        (new NowoHttpHardeningExtension())->load([[
            'csp' => ['enabled' => true],
            'no_store' => ['enabled' => true, 'routes' => ['secret_reveal']],
            'route_headers' => [
                ['match' => ['route_prefix' => 'secret_'], 'frame' => 'deny', 'referrer' => 'no-referrer', 'robots' => 'noindex', 'no_store' => true],
            ],
        ]], $container);
        $container->addCompilerPass(new RegisterListenersPass());
        $container->compile();

        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcher::class, $dispatcher);
        $priority = static function (string $class) use ($dispatcher): ?int {
            foreach ($dispatcher->getListeners('kernel.response') as $listener) {
                $object = is_array($listener) ? $listener[0] : $listener;
                if ($object instanceof $class) {
                    return $dispatcher->getListenerPriority('kernel.response', $listener);
                }
            }

            return null;
        };
        self::assertSame(RouteHeadersSubscriber::PRIORITY, $priority(RouteHeadersSubscriber::class));
        self::assertLessThan($priority(ContentSecurityPolicySubscriber::class), RouteHeadersSubscriber::PRIORITY);
        self::assertLessThan($priority(SecurityHeadersSubscriber::class), RouteHeadersSubscriber::PRIORITY);
        self::assertLessThan($priority(NoStoreSubscriber::class), RouteHeadersSubscriber::PRIORITY);
        self::assertLessThan(-128, RouteHeadersSubscriber::PRIORITY, 'After the Web Profiler toolbar nonces.');

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/s/abc');
        $request->attributes->set('_route', 'secret_reveal');
        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), 'kernel.request');
        $nonce = $request->attributes->get(ContentSecurityPolicySubscriber::REQUEST_ATTRIBUTE_NONCE);
        self::assertIsString($nonce);

        $response = new Response('<html><body><script>1</script></body></html>', 200, ['Content-Type' => 'text/html']);
        $dispatcher->dispatch(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response), 'kernel.response');

        $policy = $response->headers->get('Content-Security-Policy');
        self::assertTrue(is_string($policy));
        self::assertStringContainsString("script-src 'self' 'nonce-".$nonce."'", $policy, 'The nonce survives the rewrite.');
        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertStringEndsWith("frame-ancestors 'none'", $policy);
        self::assertStringNotContainsString("frame-ancestors 'self'", $policy);
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('nonce="'.$nonce.'"', (string) $response->getContent());

        $other = Request::create('/');
        $other->attributes->set('_route', 'home');
        $dispatcher->dispatch(new RequestEvent($kernel, $other, HttpKernelInterface::MAIN_REQUEST), 'kernel.request');
        $plain = new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
        $dispatcher->dispatch(new ResponseEvent($kernel, $other, HttpKernelInterface::MAIN_REQUEST, $plain), 'kernel.response');
        self::assertSame('SAMEORIGIN', $plain->headers->get('X-Frame-Options'));
        self::assertStringContainsString("frame-ancestors 'self'", (string) $plain->headers->get('Content-Security-Policy'));
        self::assertFalse($plain->headers->has('X-Robots-Tag'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
