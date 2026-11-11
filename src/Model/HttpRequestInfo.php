<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Model;

use Nowo\HttpHardeningBundle\Http\HttpRequestInfoFactory;

/**
 * Framework-free snapshot of the HTTP request a host port is asked about (rate-limit rule, CSP sources).
 *
 * Built by the bundle from the current `Request` (see {@see HttpRequestInfoFactory})
 * so a host port never needs an HttpFoundation type. It carries no headers, cookies, body or session: only what a
 * limit or source decision usually needs.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class HttpRequestInfo
{
    /**
     * @param string                               $method      upper-case HTTP method
     * @param string                               $scheme      `http` or `https`
     * @param string                               $host        host name, without port
     * @param int                                  $port        port the request was received on
     * @param string                               $pathInfo    raw path (`Request::getPathInfo()`)
     * @param string                               $routerPath  percent-decoded path the router matches
     * @param string|null                          $clientIp    client IP after the trusted-proxy rules
     * @param string|null                          $routeName   matched route name, when the router already ran
     * @param array<string, string|int|float|bool> $routeParams scalar route parameters, when the router already ran
     */
    public function __construct(
        public string $method,
        public string $scheme,
        public string $host,
        public int $port,
        public string $pathInfo,
        public string $routerPath,
        public ?string $clientIp = null,
        public ?string $routeName = null,
        public array $routeParams = [],
    ) {
    }

    /**
     * `scheme://host[:port]` with the default port omitted, like `Request::getSchemeAndHttpHost()`.
     */
    public function schemeAndHttpHost(): string
    {
        $defaultPort = 'https' === $this->scheme ? 443 : 80;

        return $this->scheme.'://'.$this->host.($this->port === $defaultPort ? '' : ':'.$this->port);
    }

    public function isSecure(): bool
    {
        return 'https' === $this->scheme;
    }
}
