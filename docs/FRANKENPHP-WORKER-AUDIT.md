# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/http-hardening-bundle` (`symfony-bundle`) |
| Audited revision | `v1.10.0` |
| Audit date | 2026-11-12 |
| Method | Review of every class under `src/` (subscribers, rate limiter, HTTP helpers, CSP stamper and linter, Twig extensions, command, DI extension and compiler passes), plus a kept-alive kernel test |
| **Verdict** | ✅ **Safe with kernel reset false** — no service keeps request, user or tenant data; the only per-request value (the CSP nonce) lives on the request attributes, and the only shared state (rate-limit buckets) lives in the configured cache pool, keyed per client IP |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Services](#services)
- [Test](#test)
- [Rules](#rules)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container, with
`FRANKENPHP_RESET_KERNEL=false`: the `services_resetter` never runs, so every shared service, static property and PHP
global survives from one request to the next. The bundle is **safe with kernel reset false**; it needs no `kernel.reset`
tag, no `ResetInterface` and no request-boundary subscriber of its own.

## Services

| Service | State | Reset mechanism |
|---------|-------|-----------------|
| `ContentSecurityPolicySubscriber` | none (`readonly`, configuration only); the nonce is generated per main request and stored in the `csp_nonce` request attribute | n/a: the nonce dies with the `Request` |
| `SecurityHeadersSubscriber`, `RouteHeadersSubscriber`, `NoStoreSubscriber`, `PublicCacheControlSubscriber`, `AnonymousSessionCookieStripSubscriber`, `CanonicalHostRedirectSubscriber` | none (constructor configuration only); request and token read per call | n/a |
| `RateLimitSubscriber`, `PerMinuteRateLimiter` | none in memory (`readonly`); a `RateLimiterFactory` is created per call over the cache pool; buckets are keyed by `ipKey(getClientIp())` | n/a: the buckets live in the shared cache pool, expire by themselves and are never cleared by the bundle |
| `RateLimitProviderInterface` / `RuleLimitProviderInterface` implementations (host) | host's concern | must stay stateless |
| `TenantExistenceMaskSubscriber` (+ `TenantAccessQueryInterface` / `TenantAccessCheckerInterface`) | none; user and tenant resolved per request from the token and the route | n/a; host checkers must stay stateless |
| `Http\HttpRequestInfoFactory`, `Http\SafeInternalRedirect`, `Http\RouterPath`, `Http\SafeHref`, `Http\BoundedRequestBody`, `Http\JsonUpload`, `Http\TrustedHostsEnvVarProcessor` | none (static helpers and `readonly` services; the request is passed in) | n/a |
| `Csp\CspNonceStamper`, `Csp\InlineNonceLinter`, `Twig\CspNonceExtension`, `Twig\SafeUrlExtension`, `Command\LintCspNoncesCommand` | none; the nonce is read from the current request per call | n/a |
| Value objects (`HttpRequestInfo`, `UserRef`, `RateLimitDecision`, events) | created per call, never stored in a service | n/a |

No static properties, no `static` locals, no superglobals, no `putenv` / `ini_set` / `setlocale`, no open resources, no
Doctrine access.

## Test

`tests/Functional/WorkerKeptAliveKernelTest.php` (`testAKeptAliveKernelKeepsRequestsIsolatedWithFlatMemory`) boots ONE
kernel (`tests/Functional/App/KeptAliveKernel.php`: security headers, CSP and a 400-per-minute per-IP rule on `/hooks`)
and drives 1,200 `http_kernel` `handle()` + `terminate()` cycles alternating two client IPs, never calling the services
resetter. It asserts that every response carries its own client IP and its own CSP nonce (800 distinct nonces, each in
that response's `Content-Security-Policy` header), that the two IPs keep independent buckets (each is accepted for its
first 400 requests and throttled afterwards, whatever the other does), that the security headers are on every response
including the 429s, and that memory growth after warm-up stays under 4 MiB.

## Rules

- Rule 7: the bundle never clears, resets or flushes the host's default EntityManager (it does not use Doctrine at all).
- Host-supplied implementations (rate-limit providers, tenant access checkers, CSP source contributors) must be
  stateless, or reset themselves.

## Usage recommendations in worker mode

- Back `cache.app` (or the pool used for rate limiting) with a store shared by all workers (Redis, filesystem on a
  shared volume): an `array` pool is per process, so each worker would throttle on its own.
- Configure trusted proxies so `Request::getClientIp()` is the real client; the bucket key derives from it.

## Re-audit triggers

Re-run this audit when a change adds a property to a service, a cache or memo, a listener that keeps data, nonce
generation outside the request attributes, a Doctrine dependency, or any use of `$_SERVER` / `$_ENV` / static state at
runtime.
