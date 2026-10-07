<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function in_array;

/**
 * Hardening headers that SEO/security checkers read on the HTML document.
 *
 * Edge proxies may set the same keys; PHP still applies them so an older edge
 * cannot drop nosniff, Permissions-Policy, or CORP. HSTS skips loopback by default.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -80)]
final class SecurityHeadersSubscriber
{
    public function __construct(
        private readonly string $xContentTypeOptions = 'nosniff',
        private readonly string $xFrameOptions = 'SAMEORIGIN',
        private readonly string $referrerPolicy = 'strict-origin-when-cross-origin',
        private readonly string $permissionsPolicy = 'camera=(), microphone=(), geolocation=()',
        private readonly string $crossOriginResourcePolicy = 'same-origin',
        private readonly bool $hstsEnabled = true,
        private readonly string $hsts = 'max-age=31536000; includeSubDomains',
        private readonly bool $hstsSkipLoopback = true,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;

        $this->setIfMissing($headers, 'X-Content-Type-Options', $this->xContentTypeOptions);
        $this->setIfMissing($headers, 'X-Frame-Options', $this->xFrameOptions);
        $this->setIfMissing($headers, 'Referrer-Policy', $this->referrerPolicy);
        $this->setIfMissing($headers, 'Permissions-Policy', $this->permissionsPolicy);
        $this->setIfMissing($headers, 'Cross-Origin-Resource-Policy', $this->crossOriginResourcePolicy);

        if ($this->hstsEnabled && $this->shouldSendHsts($request) && !$headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', $this->hsts);
        }
    }

    private function setIfMissing(ResponseHeaderBag $headers, string $name, string $value): void
    {
        if ('' === $value || $headers->has($name)) {
            return;
        }

        $headers->set($name, $value);
    }

    private function shouldSendHsts(Request $request): bool
    {
        if (!$request->isSecure()) {
            return false;
        }

        if (!$this->hstsSkipLoopback) {
            return true;
        }

        $host = strtolower($request->getHost());

        return !in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && !str_ends_with($host, '.localhost');
    }
}
