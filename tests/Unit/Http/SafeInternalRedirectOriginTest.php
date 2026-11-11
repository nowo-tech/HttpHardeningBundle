<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Http;

use Nowo\HttpHardeningBundle\Http\SafeInternalRedirect;
use Nowo\HttpHardeningBundle\Http\SafeInternalRedirectInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The framework-free entry point ({@see SafeInternalRedirectInterface::resolveForOrigin()}) must not weaken the
 * open-redirect validation: identical answers to {@see SafeInternalRedirect::resolve()} over an open-redirect
 * corpus, and every hostile target falls back.
 */
final class SafeInternalRedirectOriginTest extends TestCase
{
    private const ORIGIN = 'https://shell.example';

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileTargets(): iterable
    {
        yield 'protocol-relative' => ['//evil.example/phish'];
        yield 'protocol-relative with spaces' => ['  //evil.example'];
        yield 'encoded protocol-relative' => ['/%2F%2Fevil.example'];
        yield 'encoded protocol-relative lower' => ['/%2f%2fevil.example'];
        yield 'backslash' => ['/\\evil.example'];
        yield 'double backslash' => ['\\\\evil.example'];
        yield 'encoded backslash' => ['/%5cevil.example'];
        yield 'encoded backslash upper' => ['/%5Cevil.example'];
        yield 'other origin' => ['https://evil.example/phish'];
        yield 'origin as subdomain prefix' => ['https://shell.example.evil.test/'];
        yield 'origin with credentials' => ['https://shell.example@evil.example/'];
        yield 'origin without slash' => ['https://shell.example'];
        yield 'http downgrade of same host' => ['http://shell.example/x'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>alert(1)</script>'];
        yield 'relative without slash' => ['evil.example'];
        yield 'newline' => ["/ok\nLocation: https://evil.example"];
        yield 'tab' => ["/\t/evil.example"];
        yield 'nul' => ["/o\0k"];
        yield 'delete char' => ["/ok\x7f"];
        yield 'blank' => ['   '];
        yield 'empty' => [''];
        yield 'same origin then protocol-relative' => ['https://shell.example//evil.example'];
        yield 'same origin then backslash' => ['https://shell.example/\\evil.example'];
        yield 'same origin then encoded slashes' => ['https://shell.example/%2F%2Fevil.example'];
    }

    #[DataProvider('hostileTargets')]
    public function testHostileTargetsFallBackOnBothEntryPoints(string $target): void
    {
        $service = new SafeInternalRedirect();

        self::assertSame('/fallback', $service->resolveForOrigin(self::ORIGIN, $target, '/fallback'));
        self::assertSame('/fallback', $service->resolveForOrigin(self::ORIGIN.'/', $target, '/fallback'), 'A trailing slash on the origin changes nothing.');
        self::assertSame('/fallback', SafeInternalRedirect::resolve(Request::create(self::ORIGIN.'/page'), $target, '/fallback'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function safeTargets(): iterable
    {
        yield 'relative' => ['/account?tab=1', '/account?tab=1'];
        yield 'trimmed' => [' /account ', '/account'];
        yield 'same origin absolute' => ['https://shell.example/legal/privacy', '/legal/privacy'];
        yield 'same origin root' => ['https://shell.example/', '/'];
        yield 'encoded but harmless' => ['/search?q=%20a', '/search?q=%20a'];
    }

    #[DataProvider('safeTargets')]
    public function testSafeTargetsAreKept(string $target, string $expected): void
    {
        self::assertSame($expected, (new SafeInternalRedirect())->resolveForOrigin(self::ORIGIN, $target, '/fallback'));
    }

    /**
     * Old (Request) and new (origin string) entry points agree on every target, on several origins.
     */
    public function testEquivalenceWithTheRequestForm(): void
    {
        $targets = array_merge(
            array_map(static fn (array $row): string => $row[0], iterator_to_array(self::hostileTargets())),
            array_map(static fn (array $row): string => $row[0], iterator_to_array(self::safeTargets())),
            ['http://localhost:8080/x', 'http://localhost/x', 'https://shell.example:8443/x', '/x', '//x'],
        );
        $service = new SafeInternalRedirect();
        $checked = 0;
        foreach (['https://shell.example/', 'http://localhost:8080/', 'http://localhost/', 'https://shell.example:8443/'] as $url) {
            $request = Request::create($url);
            foreach ($targets as $target) {
                self::assertSame(
                    SafeInternalRedirect::resolve($request, $target, '/fb'),
                    $service->resolveForOrigin($request->getSchemeAndHttpHost(), $target, '/fb'),
                    $url.' '.$target,
                );
                ++$checked;
            }
        }
        self::assertGreaterThan(100, $checked);
    }

    public function testDefaultFallbackAndInterface(): void
    {
        $service = $this->service();

        self::assertSame('/', $service->resolveForOrigin(self::ORIGIN, '//evil.example'));
    }

    private function service(): SafeInternalRedirectInterface
    {
        return new SafeInternalRedirect();
    }
}
