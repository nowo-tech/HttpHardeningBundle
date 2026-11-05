<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use DateTimeImmutable;
use Nowo\HttpHardeningBundle\EventSubscriber\RouteHeadersSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RouteHeadersSubscriberTest extends TestCase
{
    private const POLICY = "default-src 'self'; frame-ancestors 'self'; script-src 'self' 'nonce-abc'; style-src-elem 'self' 'nonce-abc'";

    public function testFrameDenyRewritesOnlyFrameAncestors(): void
    {
        $response = new Response('<html></html>');
        $response->headers->set('Content-Security-Policy', self::POLICY);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        $this->dispatch([$this->rule(['frame' => 'deny'])], $this->secretRequest(), $response);

        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame(
            "default-src 'self'; script-src 'self' 'nonce-abc'; style-src-elem 'self' 'nonce-abc'; frame-ancestors 'none'",
            $response->headers->get('Content-Security-Policy'),
        );
    }

    public function testFrameSameOrigin(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'");

        $this->dispatch([$this->rule(['frame' => 'sameorigin'])], $this->secretRequest(), $response);

        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame("default-src 'self'; frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function testFrameDenyWithoutPolicyAddsFrameAncestorsAlone(): void
    {
        $response = new Response('{}', 200, ['Content-Type' => 'application/json']);

        $this->dispatch([$this->rule(['frame' => 'deny'])], $this->secretRequest(), $response);

        self::assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testFrameDenyAlsoRewritesAReportOnlyPolicy(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy-Report-Only', self::POLICY);

        $this->dispatch([$this->rule(['frame' => 'deny'])], $this->secretRequest(), $response);

        self::assertStringEndsWith("; frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
        self::assertStringNotContainsString("frame-ancestors 'self'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
        self::assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function testReferrer(): void
    {
        $response = new Response();
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->dispatch([$this->rule(['referrer' => 'no-referrer'])], $this->secretRequest(), $response);

        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertFalse($response->headers->has('X-Frame-Options'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function robots(): iterable
    {
        yield 'noindex shorthand' => ['noindex', 'noindex, nofollow'];
        yield 'literal' => ['noindex, noarchive', 'noindex, noarchive'];
    }

    #[DataProvider('robots')]
    public function testRobots(string $configured, string $expected): void
    {
        $response = new Response();
        $this->dispatch([$this->rule(['robots' => $configured])], $this->secretRequest(), $response);

        self::assertSame($expected, $response->headers->get('X-Robots-Tag'));
    }

    public function testNoStore(): void
    {
        $response = new Response('secret');
        $response->setPublic();
        $response->setMaxAge(60);
        $response->setEtag('abc');
        $response->setLastModified(new DateTimeImmutable('2026-01-01'));

        $this->dispatch([$this->rule(['no_store' => true])], $this->secretRequest(), $response);

        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('ETag'));
        self::assertFalse($response->headers->has('Last-Modified'));
    }

    public function testAppliesToErrorsAndRedirects(): void
    {
        $rules = [$this->rule(['frame' => 'deny', 'referrer' => 'no-referrer', 'robots' => 'noindex', 'no_store' => true])];
        foreach ([new Response('Not found', 404), new Response('Oops', 500), new RedirectResponse('/elsewhere')] as $response) {
            $this->dispatch($rules, $this->secretRequest(), $response);

            self::assertSame('DENY', $response->headers->get('X-Frame-Options'), (string) $response->getStatusCode());
            self::assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
            self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
            self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        }
    }

    /**
     * @return iterable<string, array{array{route?: list<string>, route_prefix?: list<string>, path_prefix?: list<string>}, string, ?string, bool}>
     */
    public static function matchCases(): iterable
    {
        yield 'route name' => [['route' => ['app_device_verify']], '/device', 'app_device_verify', true];
        yield 'other route name' => [['route' => ['app_device_verify']], '/device', 'app_device', false];
        yield 'route prefix' => [['route_prefix' => ['secret_']], '/s/x', 'secret_reveal', true];
        yield 'route prefix mismatch' => [['route_prefix' => ['secret_']], '/s/x', 'secrets_index', false];
        yield 'no route' => [['route_prefix' => ['secret_']], '/s/x', null, false];
        yield 'path prefix' => [['path_prefix' => ['/secrets']], '/secrets', null, true];
        yield 'path prefix child' => [['path_prefix' => ['/secrets/']], '/secrets/abc', null, true];
        yield 'path prefix lookalike' => [['path_prefix' => ['/secrets']], '/secretsauce', null, false];
        yield 'percent-encoded path prefix' => [['path_prefix' => ['/secrets']], '/%73ecrets/abc', null, true];
        yield 'percent-encoded separator' => [['path_prefix' => ['/secrets']], '/secrets%2Fabc', null, true];
        yield 'double-encoded path prefix' => [['path_prefix' => ['/secrets']], '/%2573ecrets/abc', null, false]; // ggignore: percent-encoded URL path, not a secret
        yield 'root path prefix' => [['path_prefix' => ['/']], '/anything', null, true];
        yield 'any of the lists' => [['route' => ['x'], 'route_prefix' => ['y_'], 'path_prefix' => ['/z']], '/z/1', 'other', true];
        yield 'empty rule' => [[], '/', 'home', false];
    }

    /**
     * @param array{route?: list<string>, route_prefix?: list<string>, path_prefix?: list<string>} $match
     */
    #[DataProvider('matchCases')]
    public function testMatching(array $match, string $path, ?string $route, bool $expected): void
    {
        $request = Request::create($path);
        if (null !== $route) {
            $request->attributes->set('_route', $route);
        }
        $rule = $match + ['robots' => 'noindex'];
        $response = new Response();

        $this->dispatch([$rule], $request, $response);

        self::assertSame($expected, (new RouteHeadersSubscriber())->matches($rule, $request));
        self::assertSame($expected, $response->headers->has('X-Robots-Tag'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routerPaths(): iterable
    {
        yield 'plain' => ['/secrets/abc'];
        yield 'encoded first byte (FF-M1)' => ['/%73ecrets/abc'];
        yield 'encoded last byte' => ['/secret%73/abc'];
        yield 'encoded separator' => ['/secrets%2Fabc'];
        yield 'double-encoded (no route)' => ['/%2573ecrets/abc'];
        yield 'other path' => ['/public/abc'];
    }

    /**
     * A path_prefix rule applies exactly when the real router sends the request to the protected route.
     */
    #[DataProvider('routerPaths')]
    public function testPathPrefixFollowsTheRouter(string $uri): void
    {
        $routes = new RouteCollection();
        $routes->add('secret_show', new Route('/secrets/{id}'));
        $routes->add('public_show', new Route('/public/{id}'));
        $request = Request::create($uri);
        try {
            $request->attributes->add((new UrlMatcher($routes, new RequestContext()))->matchRequest($request));
        } catch (ResourceNotFoundException) {
        }
        $response = new Response('<html></html>');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; frame-ancestors 'self'");

        $this->dispatch([['path_prefix' => ['/secrets'], 'frame' => 'deny', 'no_store' => true]], $request, $response);

        $protected = 'secret_show' === $request->attributes->get('_route');
        self::assertSame($protected, 'DENY' === $response->headers->get('X-Frame-Options'));
        self::assertSame($protected, "default-src 'self'; frame-ancestors 'none'" === $response->headers->get('Content-Security-Policy'));
        self::assertSame($protected, 'no-store, private' === $response->headers->get('Cache-Control'));
    }

    public function testEncodedPathCannotSkipAPathPrefixRule(): void
    {
        $request = Request::create('/%73ecrets/abc');
        $response = new Response('<html></html>');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; frame-ancestors 'self'");

        $this->dispatch([['path_prefix' => ['/secrets'], 'frame' => 'deny', 'referrer' => 'no-referrer', 'robots' => 'noindex', 'no_store' => true]], $request, $response);

        self::assertSame('/%73ecrets/abc', $request->getPathInfo());
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame("default-src 'self'; frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testFrameKeepsEveryPolicyValue(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', "script-src 'self'; frame-ancestors 'self'");
        $response->headers->set('Content-Security-Policy', "default-src 'none'", false);
        $response->headers->set('Content-Security-Policy-Report-Only', "img-src 'self'");
        $response->headers->set('Content-Security-Policy-Report-Only', "frame-ancestors *; style-src 'self'", false);

        $this->dispatch([$this->rule(['frame' => 'deny'])], $this->secretRequest(), $response);

        self::assertSame(
            ["script-src 'self'; frame-ancestors 'none'", "default-src 'none'; frame-ancestors 'none'"],
            $response->headers->all('Content-Security-Policy'),
        );
        self::assertSame(
            ["img-src 'self'; frame-ancestors 'none'", "style-src 'self'; frame-ancestors 'none'"],
            $response->headers->all('Content-Security-Policy-Report-Only'),
        );
    }

    public function testSubRequestAndNoRulesAreIgnored(): void
    {
        $response = new Response();
        $this->dispatch([$this->rule(['robots' => 'noindex'])], $this->secretRequest(), $response, HttpKernelInterface::SUB_REQUEST);
        self::assertFalse($response->headers->has('X-Robots-Tag'));

        $this->dispatch([], $this->secretRequest(), $response);
        self::assertFalse($response->headers->has('X-Robots-Tag'));
    }

    public function testLaterRuleWinsOnTheSameHeader(): void
    {
        $response = new Response();
        $this->dispatch([
            $this->rule(['referrer' => 'same-origin', 'frame' => 'sameorigin']),
            ['path_prefix' => ['/s'], 'referrer' => 'no-referrer', 'frame' => 'deny'],
        ], $this->secretRequest(), $response);

        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function testWithFrameAncestors(): void
    {
        self::assertSame("frame-ancestors 'none'", RouteHeadersSubscriber::withFrameAncestors('', "'none'"));
        self::assertSame(
            "default-src 'self'; frame-ancestors 'none'",
            RouteHeadersSubscriber::withFrameAncestors("Frame-Ancestors https://a.example ;default-src 'self';", "'none'"),
        );
        self::assertSame(
            "frame-ancestors-x y; frame-ancestors 'self'",
            RouteHeadersSubscriber::withFrameAncestors('frame-ancestors-x y', "'self'"),
            'Only the exact directive name is replaced.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whitespaceSeparatedFrameAncestors(): iterable
    {
        yield 'tab' => ["default-src 'self'; frame-ancestors\thttps://evil.example"];
        yield 'form feed' => ["default-src 'self'; frame-ancestors\fhttps://evil.example"];
        yield 'line feed' => ["default-src 'self'; frame-ancestors\nhttps://evil.example"];
        yield 'carriage return' => ["default-src 'self'; frame-ancestors\rhttps://evil.example"];
        yield 'several spaces' => ["default-src 'self'; frame-ancestors   https://evil.example"];
        yield 'upper case + tab' => ["default-src 'self'; FRAME-ANCESTORS\thttps://evil.example"];
        yield 'mixed case + tabs' => ["default-src 'self';\tFrame-Ancestors\t\thttps://evil.example\t"];
        yield 'name alone' => ["default-src 'self'; frame-ancestors"];
    }

    #[DataProvider('whitespaceSeparatedFrameAncestors')]
    public function testFrameAncestorsWithAnyWhitespaceIsReplaced(string $policy): void
    {
        self::assertSame("default-src 'self'; frame-ancestors 'none'", RouteHeadersSubscriber::withFrameAncestors($policy, "'none'"));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function rule(array $options): array
    {
        return ['route_prefix' => ['secret_']] + $options;
    }

    private function secretRequest(): Request
    {
        $request = Request::create('/s/abc');
        $request->attributes->set('_route', 'secret_reveal');

        return $request;
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    private function dispatch(array $rules, Request $request, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        /* @var list<array{route?: list<string>, route_prefix?: list<string>, path_prefix?: list<string>, frame?: ?string, referrer?: ?string, robots?: ?string, no_store?: bool}> $rules */
        (new RouteHeadersSubscriber($rules))(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, $response));
    }
}
