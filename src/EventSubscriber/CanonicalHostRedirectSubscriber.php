<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\EventSubscriber;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

use function in_array;
use function is_int;
use function is_string;

use const PHP_URL_HOST;

/**
 * Single 301 from `www.<apex>` to the apex host of the configured public base URL.
 *
 * Path and query string are kept. Loopback / `*.localhost` base URLs and unparsable URLs disable
 * the redirect. A `www.` base URL is reduced to its apex (the redirect always targets the apex).
 * Does not issue certificates: the edge must still present a valid certificate for `www`.
 *
 * Registered by the extension on kernel.request (priority 2048).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class CanonicalHostRedirectSubscriber
{
    public function __construct(
        private string $publicBaseUrl,
        private int $statusCode = Response::HTTP_MOVED_PERMANENTLY,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $canonical = $this->canonicalHost();
        if (null === $canonical) {
            return;
        }

        $request = $event->getRequest();
        if (strtolower($request->getHost()) !== 'www.'.$canonical) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->targetUrl($request, $canonical), $this->statusCode));
    }

    private function canonicalHost(): ?string
    {
        $host = parse_url($this->publicBaseUrl, PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return null;
        }

        $host = strtolower($host);
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost')) {
            return null;
        }

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return '' === $host ? null : $host;
    }

    private function targetUrl(Request $request, string $canonicalHost): string
    {
        $parts = parse_url($this->publicBaseUrl);
        $scheme = is_string($parts['scheme'] ?? null) && '' !== $parts['scheme'] ? $parts['scheme'] : 'https';
        $port = $parts['port'] ?? null;
        $authority = $canonicalHost;
        if (is_int($port) && !in_array($port, [80, 443], true)) {
            $authority .= ':'.$port;
        }

        $target = $scheme.'://'.$authority.$request->getPathInfo();
        $query = $request->getQueryString();
        if (is_string($query) && '' !== $query) {
            $target .= '?'.$query;
        }

        return $target;
    }
}
