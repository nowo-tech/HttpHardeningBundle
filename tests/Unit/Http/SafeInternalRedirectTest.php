<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Http;

use Nowo\HttpHardeningBundle\Http\SafeInternalRedirect;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SafeInternalRedirectTest extends TestCase
{
    public function testAllowsRelativePath(): void
    {
        self::assertSame('/account?tab=1', SafeInternalRedirect::resolve($this->request(), ' /account?tab=1 ', '/fallback'));
    }

    public function testRejectsProtocolRelative(): void
    {
        self::assertSame('/fallback', SafeInternalRedirect::resolve($this->request(), '//evil.example/phish', '/fallback'));
        self::assertSame('/fallback', SafeInternalRedirect::resolve($this->request(), '/%2F%2Fevil.example', '/fallback'));
    }

    public function testRejectsBackslashHost(): void
    {
        self::assertSame('/fallback', SafeInternalRedirect::resolve($this->request(), '/\\evil.example', '/fallback'));
        self::assertSame('/fallback', SafeInternalRedirect::resolve($this->request(), '/%5cevil.example', '/fallback'));
    }

    public function testReducesSameHostAbsoluteToPath(): void
    {
        self::assertSame('/legal/privacy', SafeInternalRedirect::resolve($this->request(), 'https://shell.example/legal/privacy', '/fallback'));
        self::assertSame('/', SafeInternalRedirect::resolve($this->request(), 'https://shell.example/', '/fallback'));
    }

    public function testRejectsExternalAbsolute(): void
    {
        self::assertSame('/fallback', SafeInternalRedirect::resolve($this->request(), 'https://evil.example/phish', '/fallback'));
        self::assertSame('/fallback', SafeInternalRedirect::resolve($this->request(), 'https://shell.example.evil.test/', '/fallback'));
    }

    public function testRejectsBlankAndControlCharacters(): void
    {
        self::assertSame('/fallback', SafeInternalRedirect::resolve($this->request(), '   ', '/fallback'));
        self::assertSame('/fallback', SafeInternalRedirect::resolve($this->request(), "/ok\npath", '/fallback'));
        self::assertSame('/fallback', SafeInternalRedirect::resolve($this->request(), 'relative', '/fallback'));
    }

    public function testResolveForCurrentRequest(): void
    {
        self::assertSame('/panel', (new SafeInternalRedirect())->resolveForCurrentRequest('/anything', '/panel'));
        self::assertSame('/panel', (new SafeInternalRedirect(new RequestStack()))->resolveForCurrentRequest('/anything', '/panel'));

        $service = new SafeInternalRedirect(new RequestStack([$this->request()]));
        self::assertSame('/settings', $service->resolveForCurrentRequest('/settings'));
        self::assertSame('/', $service->resolveForCurrentRequest('https://evil.example/'));
    }

    private function request(): Request
    {
        return Request::create('https://shell.example/dashboard');
    }
}
