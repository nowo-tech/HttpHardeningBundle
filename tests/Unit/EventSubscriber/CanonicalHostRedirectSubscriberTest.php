<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use Nowo\HttpHardeningBundle\EventSubscriber\CanonicalHostRedirectSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CanonicalHostRedirectSubscriberTest extends TestCase
{
    public function testWwwHostRedirectsToApexWith301(): void
    {
        $event = $this->dispatch('https://example.com', 'https://www.example.com/contact?ref=nav');

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://example.com/contact?ref=nav', $response->headers->get('Location'));
    }

    public function testConfiguredStatusCode(): void
    {
        $event = $this->dispatch('https://example.com', 'https://www.example.com/', statusCode: 308);

        self::assertSame(308, $event->getResponse()?->getStatusCode());
    }

    public function testLocalhostAndApexAreIgnored(): void
    {
        self::assertNull($this->dispatch('https://localhost:9453', 'https://www.localhost/')->getResponse());
        self::assertNull($this->dispatch('https://app.localhost', 'https://www.app.localhost/')->getResponse());
        self::assertNull($this->dispatch('https://example.com', 'https://example.com/')->getResponse());
        self::assertNull($this->dispatch('https://example.com', 'https://other.example/')->getResponse());
    }

    public function testWwwBaseUrlCustomPortSchemeAndInvalidUrls(): void
    {
        self::assertSame('https://example.org:8443/a', $this->dispatch('https://www.example.org:8443', 'https://www.example.org/a')->getResponse()?->headers->get('Location'));
        self::assertSame('http://example.org/a', $this->dispatch('http://example.org:80', 'http://www.example.org/a')->getResponse()?->headers->get('Location'));
        self::assertSame('https://example.org/a', $this->dispatch('//example.org', 'https://www.example.org/a')->getResponse()?->headers->get('Location'));
        self::assertNull($this->dispatch('not a url', 'https://www.example.org/a')->getResponse());
        self::assertNull($this->dispatch('', 'https://www.example.org/a')->getResponse());
        self::assertNull($this->dispatch('https://www.', 'https://www.www./a')->getResponse());
    }

    public function testIgnoresSubRequests(): void
    {
        self::assertNull($this->dispatch('https://example.com', 'https://www.example.com/', HttpKernelInterface::SUB_REQUEST)->getResponse());
    }

    private function dispatch(string $baseUrl, string $uri, int $type = HttpKernelInterface::MAIN_REQUEST, int $statusCode = 301): RequestEvent
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create($uri), $type);
        (new CanonicalHostRedirectSubscriber($baseUrl, $statusCode))($event);

        return $event;
    }
}
