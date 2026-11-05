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
