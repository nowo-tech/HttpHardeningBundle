<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\EventSubscriber;

use Nowo\HttpHardeningBundle\Http\RouterPath;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

use function in_array;
use function is_string;

/**
 * `Cache-Control: no-store, private` on sensitive pages (credentials, API keys, exports).
 *
 * A main request whose path matches one of the configured PCRE patterns or whose `_route` is listed
 * loses any cacheable policy:
 * `Cache-Control` becomes `no-store, private` and `ETag` / `Last-Modified` are removed so no
 * conditional request can revive a stored copy.
 *
 * Patterns are tried on the percent-decoded path the router matches ({@see RouterPath::of()}), so
 * `/%61dmin/keys` cannot reach the `/admin/keys` route without `no-store`, and also on the raw
 * `Request::getPathInfo()`, so patterns written for encoded paths keep matching.
 *
 * Registered at priority -1200: after the session listener (-1000, `private, must-revalidate`),
 * {@see AnonymousSessionCookieStripSubscriber} (-1024) and {@see PublicCacheControlSubscriber}
 * (-1100), so `no-store` wins whatever ran before.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class NoStoreSubscriber
{
    public const CACHE_CONTROL = 'no-store, private';

    public const PRIORITY = -1200;

    /**
     * @param list<string> $pathPatterns PCRE patterns, validated at container compile time
     * @param list<string> $routes       exact route names
     */
    public function __construct(
        private array $pathPatterns = [],
        private array $routes = [],
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->matches($event->getRequest())) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->remove('Cache-Control');
        $headers->set('Cache-Control', self::CACHE_CONTROL);
        $headers->remove('ETag');
        $headers->remove('Last-Modified');
    }

    public function matches(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (is_string($route) && in_array($route, $this->routes, true)) {
            return true;
        }

        $rawPath = $request->getPathInfo();
        $routerPath = RouterPath::of($request);
        foreach ($this->pathPatterns as $pattern) {
            if (1 === preg_match($pattern, $routerPath) || ($rawPath !== $routerPath && 1 === preg_match($pattern, $rawPath))) {
                return true;
            }
        }

        return false;
    }
}
