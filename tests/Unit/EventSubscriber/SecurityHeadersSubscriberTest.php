<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use Nowo\HttpHardeningBundle\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersSubscriberTest extends TestCase
{
    public function testSetsMissingHardeningHeadersOnSecurePublicHost(): void
    {
        $subscriber = new SecurityHeadersSubscriber();
        $request = Request::create('https://example.com/');
        $response = new Response('ok');
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $subscriber($event);

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        self::assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    public function testSkipsHstsOnLoopback(): void
    {
        $subscriber = new SecurityHeadersSubscriber();
        $request = Request::create('https://localhost:9453/');
        $response = new Response('ok');
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $subscriber($event);

        self::assertNull($response->headers->get('Strict-Transport-Security'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testDoesNotOverwriteExistingHeaders(): void
    {
        $subscriber = new SecurityHeadersSubscriber();
        $request = Request::create('https://example.com/');
        $response = new Response('ok');
        $response->headers->set('X-Content-Type-Options', 'custom');
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $subscriber($event);

        self::assertSame('custom', $response->headers->get('X-Content-Type-Options'));
    }

    public function testIgnoresSubRequests(): void
    {
        $subscriber = new SecurityHeadersSubscriber();
        $request = Request::create('https://example.com/');
        $response = new Response('ok');
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            $response,
        );

        $subscriber($event);

        self::assertNull($response->headers->get('X-Content-Type-Options'));
    }

    public function testSkipsHstsOnInsecureRequests(): void
    {
        $subscriber = new SecurityHeadersSubscriber();
        $request = Request::create('http://example.com/');
        $response = new Response('ok');
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $subscriber($event);

        self::assertNull($response->headers->get('Strict-Transport-Security'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testCanSendHstsOnLoopbackWhenSkipDisabled(): void
    {
        $subscriber = new SecurityHeadersSubscriber(hstsSkipLoopback: false);
        $request = Request::create('https://127.0.0.1/');
        $response = new Response('ok');
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $subscriber($event);

        self::assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    public function testEmptyHeaderValuesAreSkipped(): void
    {
        $subscriber = new SecurityHeadersSubscriber(xContentTypeOptions: '');
        $request = Request::create('https://example.com/');
        $response = new Response('ok');
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $subscriber($event);

        self::assertNull($response->headers->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }
}
