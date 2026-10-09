<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\HttpHardeningBundle\EventSubscriber\ContentSecurityPolicySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function strlen;

final class ContentSecurityPolicySubscriberTest extends TestCase
{
    public function testRequestStoresNonceInSharedAttribute(): void
    {
        $request = Request::create('/');
        (new ContentSecurityPolicySubscriber())(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        $nonce = $request->attributes->get('csp_nonce');
        self::assertIsString($nonce);
        self::assertSame(16, strlen((string) base64_decode($nonce, true)));
    }

    public function testRequestKeepsExistingNonceAndIgnoresSubRequests(): void
    {
        $subscriber = new ContentSecurityPolicySubscriber();
        $kernel = $this->createStub(HttpKernelInterface::class);

        $request = Request::create('/');
        $request->attributes->set('csp_nonce', 'preset');
        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame('preset', $request->attributes->get('csp_nonce'));

        $sub = Request::create('/');
        $subscriber->onRequest(new RequestEvent($kernel, $sub, HttpKernelInterface::SUB_REQUEST));
        self::assertFalse($sub->attributes->has('csp_nonce'));
    }

    public function testProdDefaultPolicy(): void
    {
        $response = $this->dispatch('/', '<html><body>ok</body></html>', kernelDebug: false);
        $csp = (string) $response->headers->get('Content-Security-Policy');

        self::assertStringStartsWith("default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; font-src 'self' data:; img-src 'self' data: blob:; frame-src 'self'; style-src-elem 'self' 'nonce-", $csp);
        self::assertStringContainsString("style-src-attr 'unsafe-inline'", $csp);
        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-[^']+';/", $csp);
        self::assertStringContainsString("connect-src 'self';", $csp);
        self::assertStringEndsWith("worker-src 'self' blob:; manifest-src 'self'", $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertStringNotContainsString('ws:', $csp, 'No WebSocket to any host in prod.');
    }

    public function testDebugPolicyRelaxations(): void
    {
        $csp = (string) $this->dispatch('/', '<html></html>', kernelDebug: true)->headers->get('Content-Security-Policy');

        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-[^']+' 'unsafe-eval'/", $csp);
        self::assertMatchesRegularExpression("/style-src-elem 'self' 'nonce-[^']+' 'unsafe-inline'/", $csp);
        self::assertStringContainsString("connect-src 'self' ws: wss:", $csp);

        $strict = (string) $this->dispatch('/', '<html></html>', kernelDebug: true, debugRelaxations: false)->headers->get('Content-Security-Policy');
        self::assertStringNotContainsString('unsafe-eval', $strict);
        self::assertStringNotContainsString('ws:', $strict);
    }

    public function testFormerSwaggerPathGetsNoUnsafeEvalInProd(): void
    {
        $csp = (string) $this->dispatch('/admin/api/doc', '<html><body>doc</body></html>', kernelDebug: false)->headers->get('Content-Security-Policy');

        self::assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function testDoesNotOverrideExistingCsp(): void
    {
        $response = new Response('<html></html>', Response::HTTP_OK, [
            'Content-Type' => 'text/html',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
        (new ContentSecurityPolicySubscriber())($this->responseEvent(Request::create('/'), $response));

        self::assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function testSkipsExcludedPathsSubRequestsAndNonHtml(): void
    {
        self::assertNull($this->dispatch('/_wdt/abcdef', '<div class="sf-toolbarreset"></div>', kernelDebug: true)->headers->get('Content-Security-Policy'));
        self::assertNull($this->dispatch('/_profiler/abc', '<html></html>', kernelDebug: true)->headers->get('Content-Security-Policy'));

        $json = new Response('{"a":1}', Response::HTTP_OK, ['Content-Type' => 'application/json']);
        (new ContentSecurityPolicySubscriber())($this->responseEvent(Request::create('/'), $json));
        self::assertNull($json->headers->get('Content-Security-Policy'));

        $sub = new Response('<html></html>', Response::HTTP_OK, ['Content-Type' => 'text/html']);
        (new ContentSecurityPolicySubscriber())($this->responseEvent(Request::create('/'), $sub, HttpKernelInterface::SUB_REQUEST));
        self::assertNull($sub->headers->get('Content-Security-Policy'));

        $custom = new ContentSecurityPolicySubscriber(excludedPathPrefixes: ['', '/api/doc']);
        $doc = new Response('<html></html>', Response::HTTP_OK, ['Content-Type' => 'text/html']);
        $custom($this->responseEvent(Request::create('/api/doc'), $doc));
        self::assertNull($doc->headers->get('Content-Security-Policy'));
    }

    public function testDetectsHtmlWithoutContentType(): void
    {
        $response = new Response('<!doctype html><html><body></body></html>');
        $response->headers->remove('Content-Type');
        (new ContentSecurityPolicySubscriber())($this->responseEvent(Request::create('/'), $response));

        self::assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function testStampsNonceOnInlineScriptsWithoutNonce(): void
    {
        $html = '<html><body><script>window.x=1</script><script src="/app.js"></script>'
            .'<script nonce="already">ok()</script>'
            .'<script type="application/json">{"a":1}</script>'
            .'<script type="module">import "x";</script></body></html>';
        $response = $this->dispatch('/', $html, kernelDebug: false);
        $content = (string) $response->getContent();
        $nonce = $this->nonceFrom($response, 'script-src');

        self::assertStringContainsString('<script nonce="'.$nonce.'">window.x=1</script>', $content);
        self::assertStringContainsString('<script src="/app.js"></script>', $content);
        self::assertStringContainsString('<script nonce="already">ok()</script>', $content);
        self::assertStringContainsString('<script type="application/json">{"a":1}</script>', $content);
        self::assertStringContainsString('<script nonce="'.$nonce.'" type="module">', $content);
    }

    public function testStampsNonceOnInlineStylesWithoutNonce(): void
    {
        $html = '<html><head><style>body{margin:0}</style><style nonce="already">.x{}</style></head><body></body></html>';
        $response = $this->dispatch('/', $html, kernelDebug: false);
        $content = (string) $response->getContent();
        $nonce = $this->nonceFrom($response, 'style-src-elem');

        self::assertStringContainsString('<style nonce="'.$nonce.'">body{margin:0}</style>', $content);
        self::assertStringContainsString('<style nonce="already">.x{}</style>', $content);
    }

    public function testStampingCanBeDisabled(): void
    {
        $html = '<html><body><script>window.x=1</script></body></html>';

        self::assertSame($html, $this->dispatch('/', $html, kernelDebug: false, stampNonces: false)->getContent());
    }

    public function testStrictLayoutDoesNotStampInjectedScriptsOrStyles(): void
    {
        $html = '<!DOCTYPE html><html lang="es" data-csp-strict-nonce><head><style>.injected{}</style></head>'
            .'<body><div class="cms"><script>alert(document.domain)</script></div>'
            .'<script nonce="template">ok()</script></body></html>';
        $response = $this->dispatch('/', $html, kernelDebug: false);

        self::assertSame($html, $response->getContent());
        self::assertStringContainsString("script-src 'self' 'nonce-", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function testStrictMarkerInsideBodyCannotDisableStamping(): void
    {
        $html = '<html lang="es"><body><p><html data-csp-strict-nonce></p><script>window.x=1</script></body></html>';

        self::assertMatchesRegularExpression('/<script nonce="[^"]+">window\.x=1<\/script>/', (string) $this->dispatch('/', $html, kernelDebug: false)->getContent());
    }

    public function testStrictMarkerMustBeAWholeAttributeName(): void
    {
        $html = '<html data-csp-strict-nonce-off="1"><body><script>window.x=1</script></body></html>';

        self::assertMatchesRegularExpression('/<script nonce="[^"]+">window\.x=1<\/script>/', (string) $this->dispatch('/', $html, kernelDebug: false)->getContent());
    }

    public function testStrictMarkerAcceptsExplicitValue(): void
    {
        $html = '<html data-csp-strict-nonce="1"><body><script>window.x=1</script></body></html>';

        self::assertSame($html, $this->dispatch('/', $html, kernelDebug: false)->getContent());
        self::assertFalse(ContentSecurityPolicySubscriber::declaresStrictNonces(new Response('no html tag')));
    }

    public function testAppendsExtraSourcesSkippingBlanksAndDuplicates(): void
    {
        $response = $this->dispatch('/', '<html></html>', kernelDebug: false, extraSources: [
            'connect-src' => ['https://*.ingest.sentry.io', 'https://sentry.io', '', "'self'"],
            'script-src' => ['https://cdn.example.test', ' '],
            'style-src-elem' => ['https://fonts.example.test'],
        ]);
        $csp = (string) $response->headers->get('Content-Security-Policy');

        self::assertStringContainsString("connect-src 'self' https://*.ingest.sentry.io https://sentry.io;", $csp);
        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-[^']+' https:\\/\\/cdn\\.example\\.test;/", $csp);
        self::assertMatchesRegularExpression("/style-src-elem 'self' 'nonce-[^']+' https:\\/\\/fonts\\.example\\.test;/", $csp);
    }

    public function testCustomDirectivesAndEmptyDirectiveIsOmitted(): void
    {
        $subscriber = new ContentSecurityPolicySubscriber(directives: [
            'default-src' => ["'none'"],
            'img-src' => [],
            'script-src' => ["'self'"],
        ]);

        self::assertSame("default-src 'none'; script-src 'self' 'nonce-abc'", $subscriber->buildPolicy(Request::create('/'), 'abc'));
    }

    public function testSourceProvidersContributeToKnownDirectives(): void
    {
        $provider = new class implements CspSourceProviderInterface {
            public function getSources(Request $request): array
            {
                return [
                    'img-src' => ['https://ik.imagekit.io'],
                    'unknown-src' => ['https://ignored.example'],
                ];
            }
        };
        $subscriber = new ContentSecurityPolicySubscriber(
            extraSources: ['img-src' => ['https://img.example']],
            sourceProviders: [$provider],
        );
        $csp = $subscriber->buildPolicy(Request::create('/'), '');

        self::assertStringContainsString("img-src 'self' data: blob: https://img.example https://ik.imagekit.io;", $csp);
        self::assertStringNotContainsString('ignored.example', $csp);
        self::assertStringNotContainsString("'nonce-", $csp);
    }

    public function testReportOnlyUsesReportOnlyHeader(): void
    {
        $response = $this->dispatch('/', '<html></html>', kernelDebug: false, reportOnly: true);

        self::assertNull($response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testWithoutRequestNonceInlineBlocksAreLeftUntouched(): void
    {
        $html = '<html><head><style>p{}</style><script>var a;</script></head></html>';
        $response = new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html']);
        $request = Request::create('/');
        $request->attributes->set('csp_nonce', ['not-a-string']);
        (new ContentSecurityPolicySubscriber())($this->responseEvent($request, $response));

        self::assertSame($html, $response->getContent());
        self::assertStringNotContainsString("'nonce-", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function testStreamedHtmlResponseGetsHeaderWithoutStamping(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo '<html><script>var a;</script></html>';
        }, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
        $request = Request::create('/');
        $request->attributes->set(ContentSecurityPolicySubscriber::REQUEST_ATTRIBUTE_NONCE, 'abc123');
        (new ContentSecurityPolicySubscriber())($this->responseEvent($request, $response));

        self::assertFalse($response->getContent(), 'Streamed body is never buffered for nonce stamping.');
        self::assertStringContainsString("'nonce-abc123'", (string) $response->headers->get('Content-Security-Policy'));
    }

    /**
     * @param array<string, list<string>> $extraSources
     */
    private function dispatch(
        string $path,
        string $html,
        bool $kernelDebug,
        array $extraSources = [],
        bool $stampNonces = true,
        bool $debugRelaxations = true,
        bool $reportOnly = false,
    ): Response {
        $request = Request::create($path);
        $subscriber = new ContentSecurityPolicySubscriber(
            kernelDebug: $kernelDebug,
            extraSources: $extraSources,
            stampNonces: $stampNonces,
            debugRelaxations: $debugRelaxations,
            reportOnly: $reportOnly,
        );
        $subscriber(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        $response = new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
        $subscriber($this->responseEvent($request, $response));

        return $response;
    }

    private function responseEvent(Request $request, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, $response);
    }

    private function nonceFrom(Response $response, string $directive): string
    {
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match('/'.preg_quote($directive, '/')." 'self' 'nonce-([^']+)'/", $csp, $m));

        return $m[1];
    }
}
