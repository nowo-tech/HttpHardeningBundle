<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;
use function is_string;

/**
 * Public GET/HEAD pages must not emit the Symfony session cookie.
 *
 * Checkers and CDNs treat Set-Cookie + Cache-Control: private as uncacheable HTML.
 * Authenticated users (any UserInterface) keep the session cookie.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
final readonly class AnonymousSessionCookieStripSubscriber
{
    /**
     * @param list<string> $publicRouteNamePrefixes
     * @param list<string> $publicRouteNames
     */
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private string $sessionCookieName,
        private array $publicRouteNamePrefixes = [],
        private array $publicRouteNames = [],
        private string $cacheControl = 'public, max-age=120, must-revalidate',
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (Request::METHOD_GET !== $request->getMethod() && Request::METHOD_HEAD !== $request->getMethod()) {
            return;
        }

        if ($this->tokenStorage->getToken()?->getUser() instanceof UserInterface) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!is_string($route) || !$this->isPublicRoute($route)) {
            return;
        }

        $response = $event->getResponse();
        $kept = [];
        $stripped = false;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $this->sessionCookieName) {
                $stripped = true;
                continue;
            }
            $kept[] = $cookie;
        }

        if (!$stripped) {
            return;
        }

        $response->headers->remove('Set-Cookie');
        foreach ($kept as $cookie) {
            $response->headers->setCookie($cookie);
        }

        if ('' !== $this->cacheControl && !$response->headers->hasCacheControlDirective('public')) {
            $response->headers->set('Cache-Control', $this->cacheControl);
        }
    }

    private function isPublicRoute(string $route): bool
    {
        foreach ($this->publicRouteNamePrefixes as $prefix) {
            if ('' !== $prefix && str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return in_array($route, $this->publicRouteNames, true);
    }
}
