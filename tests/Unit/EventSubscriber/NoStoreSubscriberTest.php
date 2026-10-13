<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use DateTimeImmutable;
use Nowo\HttpHardeningBundle\EventSubscriber\AnonymousSessionCookieStripSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\NoStoreSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\PublicCacheControlSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class NoStoreSubscriberTest extends TestCase
{
    private const PATTERN = '#^(?:/(?:admin|settings|account)(?:/|$)|/projects/[^/]+/(?:keys|settings)(?:/|$))#';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'admin root' => ['/admin', true];
        yield 'admin child' => ['/admin/mailer', true];
        yield 'settings' => ['/settings/profile', true];
        yield 'account' => ['/account', true];
        yield 'project keys' => ['/projects/abc/keys', true];
        yield 'project settings child' => ['/projects/abc/settings/webhooks', true];
        yield 'admin lookalike' => ['/administrator', false];
        yield 'project overview' => ['/projects/abc', false];
        yield 'home' => ['/', false];
    }

    #[DataProvider('paths')]
    public function testPathPatterns(string $path, bool $expected): void
    {
        $response = $this->cacheableResponse();
        $this->dispatch(new NoStoreSubscriber([self::PATTERN]), Request::create($path), $response);

        if ($expected) {
            $this->assertNoStore($response);

            return;
        }

        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
        self::assertTrue($response->headers->has('ETag'));
        self::assertTrue($response->headers->has('Last-Modified'));
    }

    public function testMatchingRoute(): void
    {
        $request = Request::create('/whatever');
        $request->attributes->set('_route', 'app_api_keys');
        $response = $this->cacheableResponse();

        $this->dispatch(new NoStoreSubscriber([], ['app_api_keys']), $request, $response);

        $this->assertNoStore($response);
    }

    public function testNonMatchingRouteAndPathAreLeftAlone(): void
    {
        $request = Request::create('/blog');
        $request->attributes->set('_route', 'blog_index');
        $response = $this->cacheableResponse();
        $subscriber = new NoStoreSubscriber([self::PATTERN], ['app_api_keys']);

        $this->dispatch($subscriber, $request, $response);

        self::assertFalse($subscriber->matches($request));
        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
    }

    public function testSubRequestIsIgnored(): void
    {
        $response = $this->cacheableResponse();
        $this->dispatch(new NoStoreSubscriber([self::PATTERN]), Request::create('/admin'), $response, HttpKernelInterface::SUB_REQUEST);

        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
        self::assertTrue($response->headers->has('ETag'));
    }

    public function testSessionListenerPolicyIsReplaced(): void
    {
        $response = new Response('secret');
        $response->headers->set('Cache-Control', 'max-age=0, must-revalidate, private');
        $response->setEtag('abc');

        $this->dispatch(new NoStoreSubscriber([self::PATTERN]), Request::create('/settings'), $response);

        $this->assertNoStore($response);
    }

    /**
     * Strip (-1024) → public cache (-1100) → no-store (-1200): no-store wins on a route listed everywhere.
     */
    public function testNoStoreWinsOverStripAndPublicCache(): void
    {
        $request = Request::create('/admin');
        $request->attributes->set('_route', 'admin_home');
        $request->cookies->set('PHPSESSID', 'abc');
        $response = new Response('secret');
        $response->headers->setCookie(Cookie::create('PHPSESSID', 'abc'));
        $response->setEtag('abc');

        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        (new AnonymousSessionCookieStripSubscriber(null, 'PHPSESSID', [], ['admin_home'], 'public, max-age=120'))($event);
        (new PublicCacheControlSubscriber(null, [], ['admin_home'], 'public, max-age=300'))($event);
        self::assertTrue($response->headers->hasCacheControlDirective('public'));

        (new NoStoreSubscriber([], ['admin_home']))($event);

        $this->assertNoStore($response);
    }

    /**
     * Reverse order (no-store first): the public cache subscriber backs off on no-store.
     */
    public function testPublicCacheBacksOffWhenNoStoreRanFirst(): void
    {
        $request = Request::create('/admin');
        $request->attributes->set('_route', 'admin_home');
        $response = $this->cacheableResponse();

        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        (new NoStoreSubscriber([], ['admin_home']))($event);
        (new PublicCacheControlSubscriber(null, [], ['admin_home'], 'public, max-age=300', ['admin_home']))($event);

        $this->assertNoStore($response);
    }

    private function cacheableResponse(): Response
    {
        $response = new Response('ok');
        $response->headers->set('Cache-Control', 'public, max-age=60');
        $response->setEtag('abc');
        $response->setLastModified(new DateTimeImmutable('2026-01-01'));

        return $response;
    }

    private function dispatch(NoStoreSubscriber $subscriber, Request $request, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $subscriber(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, $response));
    }

    private function assertNoStore(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertFalse($response->headers->hasCacheControlDirective('max-age'));
        self::assertFalse($response->headers->has('ETag'));
        self::assertFalse($response->headers->has('Last-Modified'));
    }
}
