<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Twig;

use Nowo\HttpHardeningBundle\Twig\CspNonceExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class CspNonceExtensionTest extends TestCase
{
    public function testReturnsNonceFromRequest(): void
    {
        self::assertSame('abc123', (new CspNonceExtension(new RequestStack([$this->request('abc123')])))->nonce());
    }

    public function testFallsBackToMainRequestForSubRequests(): void
    {
        $stack = new RequestStack([$this->request('main-nonce'), Request::create('/_fragment')]);

        self::assertSame('main-nonce', (new CspNonceExtension($stack))->nonce());
    }

    public function testReturnsEmptyWhenNoRequest(): void
    {
        self::assertSame('', (new CspNonceExtension(new RequestStack()))->nonce());
    }

    public function testStampsTrustedFragments(): void
    {
        $extension = new CspNonceExtension(new RequestStack([$this->request('n0nce')]));

        self::assertSame(
            '<script nonce="n0nce">window.ga=1</script><script src="/gtm.js"></script><script type="application/json">{}</script><style nonce="n0nce">.a{}</style><script nonce="x">y</script>',
            $extension->stamp('<script>window.ga=1</script><script src="/gtm.js"></script><script type="application/json">{}</script><style>.a{}</style><script nonce="x">y</script>'),
        );
        self::assertSame('', $extension->stamp(null));
        self::assertSame('<script>a</script>', (new CspNonceExtension(new RequestStack()))->stamp('<script>a</script>'), 'No nonce, nothing to stamp.');
    }

    public function testRegistersTwigFunctionAndFilter(): void
    {
        $twig = new Environment(new ArrayLoader([
            'page' => '<style nonce="{{ csp_nonce() }}"></style>{{ snippet|csp_stamp }}',
        ]));
        $twig->addExtension(new CspNonceExtension(new RequestStack([$this->request('n0nce')])));

        self::assertSame(
            '<style nonce="n0nce"></style><script nonce="n0nce">x()</script>',
            $twig->render('page', ['snippet' => '<script>x()</script>']),
        );
    }

    private function request(string $nonce): Request
    {
        $request = Request::create('/');
        $request->attributes->set('csp_nonce', $nonce);

        return $request;
    }
}
