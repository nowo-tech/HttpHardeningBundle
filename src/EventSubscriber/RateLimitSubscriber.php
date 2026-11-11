<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\EventSubscriber;

use Nowo\HttpHardeningBundle\Event\RateLimitExceededEvent;
use Nowo\HttpHardeningBundle\Http\HttpRequestInfoFactory;
use Nowo\HttpHardeningBundle\Http\RouterPath;
use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;
use Nowo\HttpHardeningBundle\Model\RateLimitDecision;
use Nowo\HttpHardeningBundle\RateLimit\PerMinuteRateLimiter;
use Nowo\HttpHardeningBundle\RateLimit\PerMinuteRateLimiterInterface;
use Nowo\HttpHardeningBundle\RateLimit\RateLimitProviderInterface;
use Nowo\HttpHardeningBundle\RateLimit\RuleLimitProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function ceil;
use function in_array;
use function max;
use function microtime;
use function min;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function strtoupper;

/**
 * Per-client-IP "N per minute" limit on configured paths: an exceeded main request answers 429 with `Retry-After`.
 *
 * Each rule matches the request path by path prefix (segment-aware: `/hooks/slack` matches `/hooks/slack` and
 * `/hooks/slack/x`, not `/hooks/slackware`; a prefix ending with `/` is a plain prefix) or PCRE pattern, and
 * optionally by HTTP method (a rule listing `GET` also matches `HEAD`, which the router sends to the `GET`
 * controller). The first matching rule wins. Prefixes and patterns are tried on the percent-decoded
 * path the router matches ({@see RouterPath::of()}) and, when it differs, on the raw `Request::getPathInfo()`: a
 * match on either applies the rule, so `/%6Cogin` (routed to `/login`) cannot skip the throttle of `/login`. Its limit is the configured `limit_per_minute`, or the
 * value of its {@see RuleLimitProviderInterface} (or deprecated {@see RateLimitProviderInterface}) read on each request (runtime settings); `0` disables the rule.
 * The bucket is {@see PerMinuteRateLimiter::ipKey()} of `Request::getClientIp()` (configure trusted proxies), per
 * rule and never per path: the plain and percent-encoded spellings of a URL share one bucket.
 *
 * A 429 dispatches a {@see RateLimitExceededEvent} when an event dispatcher is wired.
 *
 * Registered on `kernel.request` before the firewall (default priority 32), so throttled requests never reach
 * credential lookups. Readonly, no request data kept (FrankenPHP worker-safe).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class RateLimitSubscriber
{
    public const DEFAULT_PRIORITY = 32;

    public const WINDOW_SECONDS = 60;

    public const LIMITER_ID_PREFIX = 'nowo_http_hardening_';

    public const RESPONSE_BODY = ['message' => 'rate limit exceeded'];

    /**
     * @param array<string, array{path_prefixes: list<string>, path_patterns: list<string>, methods: list<string>, limit_per_minute: int}> $rules
     * @param array<string, RateLimitProviderInterface|RuleLimitProviderInterface>                                                         $limitProviders runtime limit per rule name
     */
    public function __construct(
        private PerMinuteRateLimiterInterface $limiter,
        private array $rules,
        private array $limitProviders = [],
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $rule = $this->matchingRule($request);
        if (null === $rule) {
            return;
        }

        $limit = $this->limitFor($rule, $request);
        $clientKey = PerMinuteRateLimiter::ipKey($request->getClientIp() ?? '');

        $decision = $this->limiter->attempt(self::LIMITER_ID_PREFIX.$rule, $clientKey, $limit);
        if (!$decision instanceof RateLimitDecision || $decision->accepted) {
            return;
        }

        // Whole seconds until a token is free, within the one-minute window (at least 1).
        $seconds = (float) $decision->retryAfter->format('U.u') - microtime(true);
        $retryAfter = min(self::WINDOW_SECONDS, max(1, (int) ceil($seconds)));

        $event->setResponse(new JsonResponse(self::RESPONSE_BODY, Response::HTTP_TOO_MANY_REQUESTS, [
            'Retry-After' => (string) $retryAfter,
            'Cache-Control' => 'no-store, private',
        ]));

        $this->eventDispatcher?->dispatch(new RateLimitExceededEvent($rule, $clientKey, $limit, RouterPath::of($request), $retryAfter));
    }

    /**
     * The configured limit, or the rule provider's runtime value (the framework-free port gets an
     * {@see HttpRequestInfo}; the framework-free port wins when both are implemented).
     */
    private function limitFor(string $rule, Request $request): int
    {
        $provider = $this->limitProviders[$rule] ?? null;
        if ($provider instanceof RuleLimitProviderInterface) {
            return $provider->limitPerMinute($rule, HttpRequestInfoFactory::fromRequest($request));
        }
        if ($provider instanceof RateLimitProviderInterface) {
            return $provider->limitPerMinute($rule, $request);
        }

        return $this->rules[$rule]['limit_per_minute'];
    }

    /**
     * The first rule whose method and path match, or null.
     */
    public function matchingRule(Request $request): ?string
    {
        $rawPath = $request->getPathInfo();
        $routerPath = RouterPath::of($request);
        $method = strtoupper($request->getMethod());

        foreach ($this->rules as $name => $rule) {
            if (!$this->methodMatches($method, $rule['methods'])) {
                continue;
            }
            // Matching more only throttles more: the decoded path follows the router, the raw one keeps rules
            // written for encoded paths working.
            if ($this->pathMatches($routerPath, $rule) || ($rawPath !== $routerPath && $this->pathMatches($rawPath, $rule))) {
                return $name;
            }
        }

        return null;
    }

    /**
     * An empty list matches every method. A list with `GET` also matches `HEAD`, like the router, which sends a
     * `HEAD` request to the `GET` controller (same rule, same bucket).
     *
     * @param list<string> $methods upper-cased
     */
    private function methodMatches(string $method, array $methods): bool
    {
        return [] === $methods
            || in_array($method, $methods, true)
            || ('HEAD' === $method && in_array('GET', $methods, true));
    }

    /**
     * @param array{path_prefixes: list<string>, path_patterns: list<string>, methods: list<string>, limit_per_minute: int} $rule
     */
    private function pathMatches(string $path, array $rule): bool
    {
        foreach ($rule['path_prefixes'] as $prefix) {
            $matches = str_ends_with($prefix, '/')
                ? str_starts_with($path, $prefix)
                : $path === $prefix || str_starts_with($path, $prefix.'/');
            if ($matches) {
                return true;
            }
        }

        foreach ($rule['path_patterns'] as $pattern) {
            if (1 === preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
