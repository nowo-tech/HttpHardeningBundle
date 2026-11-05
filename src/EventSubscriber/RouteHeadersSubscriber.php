<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\EventSubscriber;

use Nowo\HttpHardeningBundle\Http\RouterPath;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function rtrim;
use function str_starts_with;
use function strcspn;
use function strtolower;
use function substr;
use function trim;

/**
 * Security headers per route (`route_headers`): framing, referrer, robots and no-store for the main requests whose
 * route name, route name prefix or path prefix matches a rule.
 *
 * - `frame: deny` sets `X-Frame-Options: DENY` and `frame-ancestors 'none'`; `frame: sameorigin` sets `SAMEORIGIN`
 *   and `frame-ancestors 'self'`. Only the `frame-ancestors` directive of the policy changes: every other directive
 *   (nonces, sources) is kept. A `Content-Security-Policy-Report-Only` header, when present, gets the same rewrite;
 *   without an enforced `Content-Security-Policy` one is added with `frame-ancestors` alone.
 * - `referrer` sets `Referrer-Policy`; `robots` sets `X-Robots-Tag` (`noindex` is short for `noindex, nofollow`).
 * - `no_store: true` sets `Cache-Control: no-store, private` and removes `ETag` / `Last-Modified`.
 *
 * Applies to every response of a matching main request (errors and redirects included). When several rules match,
 * they apply in configuration order (a later rule wins on the same header).
 *
 * Registered at priority {@see self::PRIORITY} on kernel.response: after the CSP subscriber (-100), the Web Profiler
 * toolbar nonces (-128), the security headers (-80) and {@see NoStoreSubscriber} (-1200), so it has the last word.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class RouteHeadersSubscriber
{
    public const PRIORITY = -1250;

    /** Accepted `frame` values => [X-Frame-Options, frame-ancestors source]. */
    public const FRAME_VALUES = [
        'deny' => ['DENY', "'none'"],
        'sameorigin' => ['SAMEORIGIN', "'self'"],
    ];

    /** ASCII whitespace that ends a CSP directive name (space, tab, LF, FF, CR). */
    private const CSP_WHITESPACE = " \t\n\f\r";

    /** Stripped around policies and directives: the CSP whitespace plus what PHP `trim()` strips (VT, NUL). */
    private const CSP_TRIM = " \t\n\f\r\v\0";

    /** `robots: noindex` shorthand. */
    public const ROBOTS_NOINDEX = 'noindex, nofollow';

    /**
     * @param list<array{route?: list<string>, route_prefix?: list<string>, path_prefix?: list<string>, frame?: ?string, referrer?: ?string, robots?: ?string, no_store?: bool}> $rules
     */
    public function __construct(
        private array $rules = [],
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || [] === $this->rules) {
            return;
        }

        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;
        foreach ($this->rules as $rule) {
            if ($this->matches($rule, $request)) {
                self::apply($rule, $headers);
            }
        }
    }

    /**
     * Whether a rule matches the request (route name, route name prefix or segment-aware path prefix).
     *
     * Path prefixes are compared with {@see RouterPath::of()} (the percent-decoded path the router matches), so an
     * encoded byte cannot reach a protected route without its headers.
     *
     * @param array{route?: list<string>, route_prefix?: list<string>, path_prefix?: list<string>} $rule
     */
    public function matches(array $rule, Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (is_string($route) && '' !== $route) {
            if (in_array($route, $rule['route'] ?? [], true)) {
                return true;
            }
            foreach ($rule['route_prefix'] ?? [] as $prefix) {
                if ('' !== $prefix && str_starts_with($route, $prefix)) {
                    return true;
                }
            }
        }

        // The decoded path the router matches: "/%73ecrets/abc" reaches the "/secrets/{id}" route, so it must match too.
        $path = RouterPath::of($request);
        foreach ($rule['path_prefix'] ?? [] as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ('' === $prefix) {
                // "/" matches every path.
                return true;
            }
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A policy with its `frame-ancestors` directive replaced (or appended), every other directive kept.
     *
     * The directive name ends at any ASCII whitespace (space, tab, LF, FF, CR) and is compared case-insensitively, so
     * `FRAME-ANCESTORS\thttps://evil.example` is removed too: browsers honour the first `frame-ancestors` only.
     * Leading and trailing ASCII whitespace (form feed included, plus VT / NUL) is stripped like the CSP parser does. A
     * comma-joined value holds several policies: each one gets its own `frame-ancestors` (commas cannot appear inside
     * a policy).
     */
    public static function withFrameAncestors(string $policy, string $source): string
    {
        $policies = array_values(array_filter(
            explode(',', $policy),
            static fn (string $single): bool => '' !== trim($single, self::CSP_TRIM),
        ));
        if ([] === $policies) {
            $policies = [''];
        }

        return implode(', ', array_map(static fn (string $single): string => self::withFrameAncestorsInPolicy($single, $source), $policies));
    }

    private static function withFrameAncestorsInPolicy(string $policy, string $source): string
    {
        $directives = array_values(array_filter(
            array_map(static fn (string $directive): string => trim($directive, self::CSP_TRIM), explode(';', $policy)),
            static fn (string $directive): bool => '' !== $directive
                && 'frame-ancestors' !== strtolower(substr($directive, 0, strcspn($directive, self::CSP_WHITESPACE))),
        ));
        $directives[] = 'frame-ancestors '.$source;

        return implode('; ', $directives);
    }

    /**
     * @param array{frame?: ?string, referrer?: ?string, robots?: ?string, no_store?: bool} $rule
     */
    private static function apply(array $rule, ResponseHeaderBag $headers): void
    {
        $frame = $rule['frame'] ?? null;
        if (is_string($frame) && isset(self::FRAME_VALUES[$frame])) {
            [$xFrameOptions, $ancestors] = self::FRAME_VALUES[$frame];
            $headers->set('X-Frame-Options', $xFrameOptions);
            $policies = self::rewritePolicies($headers, 'Content-Security-Policy', $ancestors);
            $headers->set('Content-Security-Policy', [] !== $policies ? $policies : self::withFrameAncestors('', $ancestors));
            if ($headers->has('Content-Security-Policy-Report-Only')) {
                $headers->set('Content-Security-Policy-Report-Only', self::rewritePolicies($headers, 'Content-Security-Policy-Report-Only', $ancestors));
            }
        }

        $referrer = $rule['referrer'] ?? null;
        if (is_string($referrer) && '' !== $referrer) {
            $headers->set('Referrer-Policy', $referrer);
        }

        $robots = $rule['robots'] ?? null;
        if (is_string($robots) && '' !== $robots) {
            $headers->set('X-Robots-Tag', 'noindex' === $robots ? self::ROBOTS_NOINDEX : $robots);
        }

        if ($rule['no_store'] ?? false) {
            $headers->remove('Cache-Control');
            $headers->set('Cache-Control', NoStoreSubscriber::CACHE_CONTROL);
            $headers->remove('ETag');
            $headers->remove('Last-Modified');
        }
    }

    /**
     * Every value of a (possibly repeated) policy header with its `frame-ancestors` rewritten: each enforced policy
     * is kept, none is collapsed into the first one.
     *
     * @return list<string>
     */
    private static function rewritePolicies(ResponseHeaderBag $headers, string $name, string $ancestors): array
    {
        $policies = [];
        foreach ($headers->all($name) as $policy) {
            $policies[] = self::withFrameAncestors((string) $policy, $ancestors);
        }

        return $policies;
    }
}
