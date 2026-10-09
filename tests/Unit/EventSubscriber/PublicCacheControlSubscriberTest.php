<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use Nowo\HttpHardeningBundle\EventSubscriber\AnonymousSessionCookieStripSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\PublicCacheControlSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function in_array;

final class PublicCacheControlSubscriberTest extends TestCase
{
    private const SESSION_COOKIE = 'APP_SESSID';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function publicRoutes(): iterable
    {
        yield 'home' => ['site_home', 'max-age=120'];
        yield 'legal' => ['legal_privacy', 'max-age=120'];
        yield 'exact page' => ['landing', 'max-age=120'];
        yield 'sitemap' => ['nowo_seo_kit_sitemap', 'max-age=3600'];
        yield 'manifest' => ['nowo_pwa_manifest', 'max-age=3600'];
    }

    #[DataProvider('publicRoutes')]
    public function testAnonymousPublicResponsesBecomeCacheable(string $route, string $maxAge): void
    {
        $response = $this->dispatch($route);
        $page = 'max-age=120' === $maxAge;

        self::assertTrue($response->headers->hasCacheControlDirective($page ? 'private' : 'public'));
        self::assertStringContainsString($maxAge, (string) $response->headers->get('Cache-Control'));
        self::assertSame($page, in_array('Cookie', $response->getVary(), true));
        self::assertNull($response->getEtag());
    }

    public function testLeavesOtherCasesAlone(): void
    {
        self::assertSame('private', $this->cacheControl($this->dispatch('dashboard_home')));
        self::assertSame('private', $this->cacheControl($this->dispatch(null)));
        self::assertSame('private', $this->cacheControl($this->dispatch('site_home', method: 'POST')));
        self::assertSame('private', $this->cacheControl($this->dispatch('site_home', status: 404)));
        self::assertSame('private', $this->cacheControl($this->dispatch('site_home', signedIn: true)));
        self::assertSame('private', $this->cacheControl($this->dispatch('site_home', sessionCookie: true)));
        self::assertSame('no-store, private', $this->cacheControl($this->dispatch('site_home', cacheControl: 'no-store')));
        self::assertSame('private', $this->cacheControl($this->dispatch('site_home', mainRequest: false)));
        self::assertSame('private', $this->cacheControl($this->dispatch('site_home', requestCookies: [self::SESSION_COOKIE => 'id'])), 'Flashes / signed-in chrome.');
        self::assertSame('private', $this->cacheControl($this->dispatch('site_home', requestCookies: ['REMEMBERME' => 'x'])));
        self::assertSame('private', $this->cacheControl($this->dispatch('nowo_seo_kit_sitemap', requestCookies: ['Cookie_Consent' => 'x'])));
    }

    public function testRememberMeOptOutCanBeDisabled(): void
    {
        $response = $this->dispatch('site_home', requestCookies: ['REMEMBERME' => 'x'], rememberMeCookie: '');

        self::assertSame('max-age=120, must-revalidate, private', $this->cacheControl($response));
    }

    public function testEmptyPolicyLeavesResponseAlone(): void
    {
        self::assertSame('private', $this->cacheControl($this->dispatch('site_home', pagePolicy: '')));
    }

    public function testConsentCookiesStillGetABrowserCachedCopyThatVariesOnCookie(): void
    {
        $response = $this->dispatch('site_home', requestCookies: ['Cookie_Consent' => 'x', 'Cookie_Category_analytics' => 'true']);

        self::assertSame('max-age=120, must-revalidate, private', $this->cacheControl($response));
        self::assertContains('Cookie', $response->getVary());
    }

    public function testPagesMadePublicUpstreamBecomePrivateAndVaryOnCookie(): void
    {
        $response = $this->dispatch('site_home', cacheControl: 'public, max-age=60', requestCookies: [self::SESSION_COOKIE => 'stripped']);

        self::assertSame('max-age=120, must-revalidate, private', $this->cacheControl($response));
        self::assertContains('Cookie', $response->getVary());
    }

    public function testNoncedPagesNeverKeepAnEtag(): void
    {
        $response = $this->dispatch('site_home', etag: 'abc', nonce: true);
        self::assertNull($response->getEtag());

        $files = $this->dispatch('nowo_seo_kit_sitemap', etag: 'abc', nonce: true);
        self::assertSame('"abc"', $files->getEtag(), 'Files carry no nonce.');

        $noNonce = $this->dispatch('site_home', etag: 'abc');
        self::assertSame('"abc"', $noNonce->getEtag());
    }

    public function testCoexistsWithAnonymousSessionStrip(): void
    {
        $tokens = new TokenStorage();
        $request = new Request([], [], ['_route' => 'site_home'], [self::SESSION_COOKIE => 'id']);
        $response = new Response('ok');
        $response->headers->setCookie(Cookie::create(self::SESSION_COOKIE, 'id'));
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);

        // Priority order: strip (-1024) runs before public cache (-1100).
        (new AnonymousSessionCookieStripSubscriber($tokens, self::SESSION_COOKIE, ['site_']))($event);
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->subscriber($tokens)($event);

        self::assertSame([], $response->headers->getCookies());
        self::assertSame('max-age=120, must-revalidate, private', $this->cacheControl($response));
        self::assertContains('Cookie', $response->getVary());
    }

    /**
     * @param array<string, string> $requestCookies
     */
    private function dispatch(
        ?string $route,
        string $method = 'GET',
        int $status = 200,
        bool $signedIn = false,
        bool $sessionCookie = false,
        ?string $cacheControl = 'private',
        bool $mainRequest = true,
        array $requestCookies = [],
        string $rememberMeCookie = 'REMEMBERME',
        string $pagePolicy = PublicCacheControlSubscriber::DEFAULT_PAGE_CACHE_CONTROL,
        ?string $etag = null,
        bool $nonce = false,
    ): Response {
        $request = Request::create('/', $method, [], $requestCookies);
        if (null !== $route) {
            $request->attributes->set('_route', $route);
        }
        if ($nonce) {
            $request->attributes->set('csp_nonce', 'n0nce');
        }
        $response = new Response('ok', $status, ['Cache-Control' => $cacheControl]);
        if (null !== $etag) {
            $response->setEtag($etag);
        }
        if ($sessionCookie) {
            $response->headers->setCookie(Cookie::create(self::SESSION_COOKIE, 'id'));
        }

        $tokens = new TokenStorage();
        if ($signedIn) {
            $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        }

        $this->subscriber($tokens, $rememberMeCookie, $pagePolicy)(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
            $response,
        ));

        return $response;
    }

    private function subscriber(
        TokenStorage $tokens,
        string $rememberMeCookie = 'REMEMBERME',
        string $pagePolicy = PublicCacheControlSubscriber::DEFAULT_PAGE_CACHE_CONTROL,
    ): PublicCacheControlSubscriber {
        return new PublicCacheControlSubscriber(
            $tokens,
            ['site_', 'legal_', ''],
            ['landing'],
            $pagePolicy,
            ['nowo_seo_kit_sitemap', 'nowo_pwa_manifest'],
            PublicCacheControlSubscriber::DEFAULT_FILE_CACHE_CONTROL,
            self::SESSION_COOKIE,
            $rememberMeCookie,
        );
    }

    private function cacheControl(Response $response): string
    {
        return (string) $response->headers->get('Cache-Control');
    }
}
