<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\EventSubscriber;

use Nowo\HttpHardeningBundle\RateLimit\PerMinuteRateLimiter;
use Nowo\HttpHardeningBundle\RateLimit\RateLimitProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimit;

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
 * Each rule matches `Request::getPathInfo()` by path prefix (segment-aware: `/hooks/slack` matches `/hooks/slack`
 * and `/hooks/slack/x`, not `/hooks/slackware`; a prefix ending with `/` is a plain prefix) or PCRE pattern, and
 * optionally by HTTP method. The first matching rule wins. Its limit is the configured `limit_per_minute`, or the
 * value of its {@see RateLimitProviderInterface} read on each request (runtime settings); `0` disables the rule.
 * The bucket is {@see PerMinuteRateLimiter::ipKey()} of `Request::getClientIp()` (configure trusted proxies).
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
     * @param array<string, RateLimitProviderInterface>                                                                                    $limitProviders runtime limit per rule name
     */
    public function __construct(
        private PerMinuteRateLimiter $limiter,
        private array $rules,
        private array $limitProviders = [],
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

        $provider = $this->limitProviders[$rule] ?? null;
        $limit = null !== $provider ? $provider->limitPerMinute($rule, $request) : $this->rules[$rule]['limit_per_minute'];

        $rateLimit = $this->limiter->consume(self::LIMITER_ID_PREFIX.$rule, PerMinuteRateLimiter::ipKey($request->getClientIp() ?? ''), $limit);
        if (!$rateLimit instanceof RateLimit || $rateLimit->isAccepted()) {
            return;
        }

        // Whole seconds until a token is free, within the one-minute window (at least 1).
        $seconds = (float) $rateLimit->getRetryAfter()->format('U.u') - microtime(true);
        $retryAfter = min(self::WINDOW_SECONDS, max(1, (int) ceil($seconds)));

        $event->setResponse(new JsonResponse(self::RESPONSE_BODY, Response::HTTP_TOO_MANY_REQUESTS, [
            'Retry-After' => (string) $retryAfter,
            'Cache-Control' => 'no-store, private',
        ]));
    }

    /**
     * The first rule whose method and path match, or null.
     */
    public function matchingRule(Request $request): ?string
    {
        $path = $request->getPathInfo();
        $method = strtoupper($request->getMethod());

        foreach ($this->rules as $name => $rule) {
            if ([] !== $rule['methods'] && !in_array($method, $rule['methods'], true)) {
                continue;
            }
            if ($this->pathMatches($path, $rule)) {
                return $name;
            }
        }

        return null;
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
