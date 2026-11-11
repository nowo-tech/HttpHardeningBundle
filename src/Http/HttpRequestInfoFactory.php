<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Http;

use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;
use Symfony\Component\HttpFoundation\Request;

use function is_array;
use function is_scalar;
use function is_string;
use function strtoupper;

/**
 * Adapter: builds the framework-free {@see HttpRequestInfo} the host ports receive from a Symfony `Request`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class HttpRequestInfoFactory
{
    public static function fromRequest(Request $request): HttpRequestInfo
    {
        $route = $request->attributes->get('_route');
        $params = $request->attributes->get('_route_params');
        $scalarParams = [];
        if (is_array($params)) {
            foreach ($params as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $scalarParams[$name] = $value;
                }
            }
        }

        return new HttpRequestInfo(
            method: strtoupper($request->getMethod()),
            scheme: $request->getScheme(),
            host: $request->getHost(),
            port: (int) $request->getPort(),
            pathInfo: $request->getPathInfo(),
            routerPath: RouterPath::of($request),
            clientIp: $request->getClientIp(),
            routeName: is_string($route) ? $route : null,
            routeParams: $scalarParams,
        );
    }
}
