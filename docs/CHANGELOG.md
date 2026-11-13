# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.10.0] - 2026-11-11](#1100---2026-11-11)
- [[1.9.0] - 2026-11-05](#190---2026-11-05)
- [[1.8.0] - 2026-10-31](#180---2026-10-31)
- [[1.7.2] - 2026-10-21](#172---2026-10-21)
- [[1.7.1] - 2026-10-20](#171---2026-10-20)
- [[1.7.0] - 2026-10-16](#170---2026-10-16)
- [[1.6.0] - 2026-10-15](#160---2026-10-15)
- [[1.5.0] - 2026-10-14](#150---2026-10-14)
- [[1.4.0] - 2026-10-13](#140---2026-10-13)
- [[1.3.0] - 2026-10-13](#130---2026-10-13)
- [[1.2.0] - 2026-10-11](#120---2026-10-11)
- [[1.1.0] - 2026-10-09](#110---2026-10-09)
- [[1.0.4] - 2026-10-09](#104---2026-10-09)
- [[1.0.3] - 2026-10-07](#103---2026-10-07)
- [[1.0.2] - 2026-10-07](#102---2026-10-07)
- [[1.0.1] - 2026-10-07](#101---2026-10-07)
- [[1.0.0] - 2026-10-07](#100---2026-10-07)

## [Unreleased]

### Changed

- CI and `make test-coverage`: the Clover statement-coverage gate is now **100%** (was 99%). The suite is already at 100%; the gate only stops a regression from merging.

## [1.10.0] - 2026-11-11

Target: **1.10.0** (minor, no breaking change). See [UPGRADING](UPGRADING.md#unreleased).

### Added

- **Framework-free ports (REQ-ARCH-001 rule 3):** `Security\TenantAccessQueryInterface::hasAnyAccess(UserRef, string)`,
  `RateLimit\RuleLimitProviderInterface::limitPerMinute(string, HttpRequestInfo)` and
  `Csp\CspSourceContributorInterface::getSources(HttpRequestInfo)` (autoconfigured, tag
  `nowo_http_hardening.csp_source_contributor`), with the bundle-owned value objects `Model\UserRef` (user identifier +
  own roles) and `Model\HttpRequestInfo` (method, scheme, host, port, raw and decoded path, client IP, route name,
  scalar route parameters), built by `Http\HttpRequestInfoFactory`. When a service implements both the old and the new
  port, the new one is called (once).
- **Interfaces for the services (rule 1):** `RateLimit\PerMinuteRateLimiterInterface` (`accept()`, new `attempt()`
  returning a framework-free `Model\RateLimitDecision`) and `Http\SafeInternalRedirectInterface`
  (`resolveForCurrentRequest()`, new `resolveForOrigin()`), both aliased to the existing services.
  `SafeInternalRedirect::resolve()`, `resolveForCurrentRequest()` and `resolveForOrigin()` share one validation.
- **CSP source order:** sources of services on the deprecated `CspSourceProviderInterface` come first, then those of
  `CspSourceContributorInterface` services. A service implementing **both** ports is asked once, through the new
  port, so its sources now come after the legacy-only providers' (the policy has the same sources; only the order in
  the header can change).
- **Security events (rule 4):** `Event\TenantExistenceMaskedEvent` (tenant id, decoded path, user identifier, route
  name) after a masked denial and `Event\RateLimitExceededEvent` (rule, client key, limit, decoded path, `Retry-After`,
  route name when known) after a 429; scalars only, dispatched when an event dispatcher is wired. The client key is an
  HMAC-SHA256 of the client IP keyed with `kernel.secret` (`ip_unkeyed` without a secret), not the reversible cache
  bucket hash; the bucket key is unchanged, so existing buckets survive the upgrade. `userIdentifier` is personal
  data and `path` may carry secrets (a token in a URL segment): log the route name, or treat both as sensitive.
- `tenant_existence_mask.access_checker` unset now resolves to the `TenantAccessQueryInterface` alias when the host
  registered one, else to the `TenantAccessCheckerInterface` alias (unchanged); with neither the container build still
  fails.
- Tests: old-vs-new port equivalence (tenant decision matrix, rate-limit throttling, CSP policy, open-redirect corpus)
  and a DDD-host kernel wired only with the new ports that answers like the standard host.

### Security

- **CSP sources are validated (audit MJ-H-L1).** A source from a `CspSourceProviderInterface` /
  `CspSourceContributorInterface` service containing whitespace, `;`, `,` or a control character is skipped: it
  could add a directive (`https://x; script-src 'unsafe-inline'`), a source or a second policy, or drop the whole
  header (CR / LF). Configured sources containing `;`, `,` or a control character are skipped too (a space in a
  configured entry still separates two sources).

### Deprecated

- `Security\TenantAccessCheckerInterface`, `RateLimit\RateLimitProviderInterface` and
  `Csp\CspSourceProviderInterface` (they take Symfony `UserInterface` / `Request`): use the framework-free ports above.
  They keep working until 2.0; the container build reports a deprecation for each host service that implements one
  without its replacement.

### Changed

- Docs: USAGE section "Standard host and DDD host" (replaces "Using it from a DDD / hexagonal application"): both host
  paths, the framework-free ports, value objects and events.
- `composer.json` requires `symfony/deprecation-contracts` and `symfony/event-dispatcher-contracts` explicitly (both were
  already installed through `symfony/http-kernel`).
- `RateLimitSubscriber` depends on `PerMinuteRateLimiterInterface` (the service id `PerMinuteRateLimiter` is
  unchanged), `SafeUrlExtension` on `SafeInternalRedirectInterface`.

## [1.9.0] - 2026-11-05

Minor release. See [UPGRADING](UPGRADING.md#to-190).

### Added

- **Headers per route** (`route_headers`, opt-in, KIT-69 from the Beacon v1.122.0 audit): `RouteHeadersSubscriber`
  (`kernel.response` priority -1250, after the CSP nonce listener, the Web Profiler toolbar and `no_store`) applies, on
  every response of main requests matching `match.route` / `match.route_prefix` / `match.path_prefix` (errors and
  redirects included): `frame: deny|sameorigin` (`X-Frame-Options` + CSP `frame-ancestors`, merged into the existing
  policy and report-only policy without dropping other directives), `referrer` (`Referrer-Policy`), `robots`
  (`X-Robots-Tag`, `noindex` = `noindex, nofollow`) and `no_store`. Rules are validated at container build. Registered
  only when a rule is configured; existing defaults and behaviour are unchanged. `match.path_prefix` must start with `/`
  (checked at container build) and is compared, segment-aware, with the percent-decoded path the router matches
  (`rawurldecode(getPathInfo())`, decoded once like `UrlMatcher`), so `/%73ecrets/abc` cannot reach the `/secrets/{id}`
  route without the rule's headers. `frame` rewrites every value of a repeated `Content-Security-Policy` /
  Report-Only header, and recognises `frame-ancestors` whatever its case and the ASCII whitespace after its name (tab,
  form feed…). A rule replaces the global / host value even when that one is stricter (documented).

### Security

- **`no_store.path_patterns` and percent-encoded paths:** the patterns were matched against the raw
  `Request::getPathInfo()` while the router matches the decoded path, so `/%61dmin/keys` reached the `/admin/keys`
  route without `Cache-Control: no-store`. Patterns are now tried on the decoded path the router matches (and still on
  the raw path, so patterns written for encoded paths keep matching). New `Http\RouterPath::of()` helper.
- **`rate_limit` and percent-encoded paths (FF-M2, since 1.8.0):** `rules.*.path_prefixes` / `path_patterns` were
  matched against the raw `Request::getPathInfo()`, so `/%6Cogin` (routed to `/login`, and accepted by `form_login` /
  `json_login` `check_path`) skipped the throttle of the `/login` rule. Prefixes and patterns are now tried on the
  decoded path the router matches and on the raw path: a match on either applies the rule. The bucket is still per
  rule and client IP (never per path), so plain and encoded spellings share it. A double-encoded path
  (`/%256Cogin`) is decoded once, like the router, which does not route it to `/login` either.
- **`tenant_existence_mask` and percent-encoded paths (FF-L3, since 1.7.0):** the patterns ran on the raw path, so
  `/projects/%61bc…` (routed like `/projects/abc…`) answered the 403 instead of the masked 404, reopening the existence
  oracle. Patterns are now tried on the decoded path first (its capture is the tenant id the route receives), then on
  the raw path.
- **`rate_limit` and `HEAD` (FF-L4, since 1.8.0):** a rule with `methods: [GET]` did not match `HEAD`, which the
  router sends to the same `GET` controller, so `HEAD /feed` was never throttled. A rule listing `GET` now also
  matches `HEAD`, in the same bucket.
- **`route_headers` `frame` and unusual host policies (FF-I6):** a directive preceded by a form feed
  (`;\fframe-ancestors …`) and a comma-joined value (`default-src 'self', frame-ancestors …`) kept the host
  `frame-ancestors`. Directives are now trimmed of every ASCII whitespace, and each comma-separated policy of a value
  gets its own `frame-ancestors`.
- **`route_headers` `robots` (FF-I2):** any control character (not only CR / LF) now fails the container build.

## [1.8.0] - 2026-10-31

PerMinuteRateLimiter (runtime N-per-minute limit, hashed client IP) and opt-in rate_limit rules answering 429 with Retry-After. See [UPGRADING](UPGRADING.md#to-180).

### Added

- **Per-minute rate limit** (needs `symfony/rate-limiter`, suggested): `RateLimit\PerMinuteRateLimiter` service (sliding window "N per minute" over a PSR-6 pool, `cache.app` by default, with the limit read at each call; `accept()`, `consume()` and the hashed `ipKey()` bucket), registered whenever `symfony/rate-limiter` is installed.
- Opt-in `rate_limit` section (`enabled: false` by default): `RateLimitSubscriber` (`kernel.request`, priority 32, before the firewall) answers `429` JSON with `Retry-After` and `Cache-Control: no-store, private` per client IP on named rules (`path_prefixes` segment-aware, `path_patterns`, `methods`, `limit_per_minute`, optional runtime `limit_provider` implementing `RateLimit\RateLimitProviderInterface`).
- `symfony/rate-limiter` in `require-dev` and `suggest`. Existing defaults and behaviour are unchanged.

## [1.7.2] - 2026-10-21

PHPStan CI job, release-check starts with ensure-up, worktree-safe co-author check. See [UPGRADING](UPGRADING.md#to-172).

### Changed

- CI: new `phpstan` job runs `composer phpstan` on every push and pull request, so PHPStan findings fail the build (REQ-CS-006).
- `release-check` runs sequentially and starts with `ensure-up` (REQ-MAKE-002).

### Fixed

- `.scripts/check-no-cursor-coauthor.sh` also runs inside git worktrees (`.git` file instead of directory).

## [1.7.1] - 2026-10-20

Documentation: wave-1 REQ compliance (README, CONTRIBUTING, issue templates, Spec Kit inventory). See [UPGRADING](UPGRADING.md#to-171).

### Changed

- Documentation: wave-1 REQ compliance — README preamble (blurb, compatibility note, FrankenPHP Friendly banner + claim) and Features section, Quick start before Requirements, documentation list with GitHub CI doc, new `docs/GITHUB_CI.md`, canonical issue templates, CONTRIBUTING Code of Conduct / Cursor Agent / post-commit check, RELEASE post-commit check, TOCs, canonical baseline inventory 25/25 (FR-TH-001, FR-UPL-001/002 added).

## [1.7.0] - 2026-10-16

### Added

- **Tenant existence mask (`tenant_existence_mask`, opt-in).** No existence oracle on tenant URLs: on `kernel.exception` (priority 16, before the firewall `ExceptionListener`), an `AccessDeniedException` or `AccessDeniedHttpException` on a path matching `tenant_existence_mask.path_patterns` (PCRE; tenant id = `tenant` named group, else group 1) becomes a plain `NotFoundHttpException` — the same answer as an unknown id — when the signed-in user has no access at all to that tenant. Members with a too-low role keep 403; anonymous visitors keep the login redirect / 401. The 404 has no `previous` (the firewall walks the chain and would re-raise the 403). The host implements the new SPI `Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface::hasAnyAccess(UserInterface $user, string $tenantId): bool` (`access_checker`, default: the interface alias). Patterns without a capture group, and `enabled: true` without patterns, fail the container build (spec `006`).

### Changed

- `igor.json` excludes `tests`, `demo`, `vendor`, `var` and `coverage` like the other Nowo bundles (test doubles are not worker services); `rector.php` skips `RemoveParentDelegatingConstructorRector` on `JsonUploadException`, whose constructor promotes the public `reason` property.

### Dependencies

- Dev only: `symfony/framework-bundle`, `symfony/security-bundle`, `symfony/browser-kit` (functional kernel tests). No runtime changes.

## [1.6.0] - 2026-10-15

### Added

- **`nowo_trusted_hosts` env var processor (`trusted_hosts.*`).** `%env(nowo_trusted_hosts:SYMFONY_TRUSTED_HOSTS)%` for `framework.trusted_hosts`: the named variable, then `fallback_env` (`TRUSTED_HOSTS`), then — outside `local_environments` — a regex derived from the host of `public_url_env` (`DEFAULT_URI`) plus `internal_hosts`, so production never runs with an empty Host allowlist (Nowo REQ-HDR-006 item 1). Apps can drop their host processor.
- **`JsonUpload::decodeObject(?UploadedFile, int $maxBytes = 2 MiB)`.** Bounded decode of an uploaded JSON object (admin / settings imports): declared size first, then at most cap + 1 bytes read; a JSON list or invalid JSON is refused. Throws `Http\Exception\JsonUploadException` (`reason` = `missing_file` / `too_large` / `invalid_json`). The upload twin of `BoundedRequestBody` (Nowo REQ-INSTANCE-003).

## [1.5.0] - 2026-10-14

### Added

- **Bounded request body**: `Nowo\HttpHardeningBundle\Http\BoundedRequestBody::read(Request $request, int $maxBytes): string` rejects a `Content-Length` above the cap before reading, otherwise streams at most cap + 1 bytes (chunked bodies without `Content-Length` included) and throws `Http\Exception\RequestBodyTooLargeException` (an `HttpException`, status 413, with `getMaxBytes()` / `isDeclared()`). `tryRead()` returns `null` instead (spec `005`).
- **Worker-safe Web Profiler CSP handler**: `WebProfilerCspHandlerPass` makes WebProfilerBundle's `web_profiler.csp.handler` non-shared, so a `/_wdt` or `/_profiler` request no longer strips `Content-Security-Policy` (and toolbar nonces) from later pages in FrankenPHP worker mode. New key `web_profiler.isolate_csp_handler` (default `true`); no-op without WebProfilerBundle (spec `005`).

### Dependencies

- Lockfile refresh from `composer update --no-install` (Symfony components 7.4.21, `symfony/phpunit-bridge` 8.1.9). No constraint changes.

## [1.4.0] - 2026-10-13

### Added

- **No-store paths** (`no_store`, opt-in): `NoStoreSubscriber` (`kernel.response` priority -1200, after the session listener, the anonymous session strip and public cache) sets `Cache-Control: no-store, private` and removes `ETag` / `Last-Modified` on main requests whose path matches `no_store.path_patterns` (PCRE, on `getPathInfo()`) or whose `_route` is in `no_store.routes`. Invalid patterns fail at container build with an `InvalidConfigurationException`. Registered once, only when enabled (spec `004`).

### Dependencies

- Dev lockfile: `nowo-tech/phpstan-frankenphp` 1.3.0; `symfony/yaml` listed under `packages-dev` (matches 1.3.0 `require-dev`). No runtime changes.

## [1.3.0] - 2026-10-13

### Fixed

- `SecurityHeadersSubscriber` and `AnonymousSessionCookieStripSubscriber` ran twice per response: they carried `#[AsEventListener]` and the extension also tagged them (`kernel.event_listener`) with autoconfigure on. The attributes and autoconfigure are gone; the extension tag is the single registration (priorities unchanged).
- The container no longer fails to compile without SecurityBundle when `anonymous_session_strip` or `public_cache` is enabled: `security.token_storage` is an optional reference and both subscribers accept a null token storage (every visitor is then anonymous).

### Changed

- `symfony/yaml` moved from `require` to `require-dev` (not used by the bundle at runtime).

## [1.2.0] - 2026-10-11

### Added

- `csp.nonce_free_style_path_prefixes` (default `[]`): on matching paths `style-src-elem` carries `'unsafe-inline'` instead of the nonce, for editors that inject `<style>` at runtime (CKEditor 5). Segment-aware prefix match; `script-src` keeps its nonce. Lets hosts drop their own CSP listener (spec `003`).

## [1.1.0] - 2026-10-09

### Added

- **Content-Security-Policy** (`csp`, opt-in): `ContentSecurityPolicySubscriber` issues a per-request nonce in the `csp_nonce` request attribute (shared nowo-tech convention), builds the policy from config (`default_src` … `manifest_src` plus `*_extra` lists), adds `'unsafe-eval'` / `'unsafe-inline'` (style elements) / `ws: wss:` only in `kernel.debug`, stamps the nonce on inline `<script>`/`<style>` unless `<html>` carries `data-csp-strict-nonce`, skips `/_wdt` and `/_profiler`, supports `report_only`.
- `CspSourceProviderInterface` (autoconfigured tag `nowo_http_hardening.csp_source_provider`) for runtime sources such as an image CDN origin.
- Twig helpers `csp_nonce()` and `|csp_stamp` (`CspNonceExtension`, registered when CSP is enabled and `twig/twig` is installed); `CspNonceStamper`.
- Console command `nowo:http-hardening:lint-csp-nonces [dirs...]` (default `templates/`) and `InlineNonceLinter`: non-zero exit on inline Twig blocks without a nonce.
- **Public cache** (`public_cache`, opt-in): `PublicCacheControlSubscriber` (priority -1100, after the session strip) sets `private, max-age=120, must-revalidate` + `Vary: Cookie` on anonymous public pages and `public, max-age=3600, must-revalidate` on file routes; session / remember-me cookies opt out; nonce'd pages lose their ETag; upstream `public` on pages is normalised.
- **Canonical host** (`canonical_host`, opt-in): `CanonicalHostRedirectSubscriber` — single 301 (configurable 301/302/307/308) from `www.<apex>` to the apex of `base_url`.
- **Safe URLs**: `SafeInternalRedirect` service (open-redirect guard) and `SafeHref`; optional `safe_internal_path()` / `safe_href()` Twig functions (`safe_urls.twig_functions`).

### Changed

- `twig/twig` and `symfony/console` added to `require-dev` and `suggest` (optional integrations; no new runtime requirement).

## [1.0.4] - 2026-10-09

### Dependencies

- Dev lockfile refresh: `phpstan/phpstan` 2.3.1, `nowo-tech/phpstan-frankenphp` 1.2.3. No runtime changes.

## [1.0.3] - 2026-10-07

### Changed

- Spec Kit baseline status/alignment set to **v1.0.3**.
- Installation docs link to [USAGE.md](USAGE.md).

## [1.0.2] - 2026-10-07

### Added

- Integrator docs: `USAGE.md`, `ENGRAM.md`, `SPEC-DRIVEN-DEVELOPMENT.md`, `SPEC-KIT.md`, `PSR.md` (REQ-CS-007).
- Spec Kit baseline `specs/001-baseline/{spec.md,code-inventory.md}` (5/5 `src/` mapped) and tailored constitution.
- README canonical `## Documentation` order, full badge set, and “Found this useful?” line.

### Changed

- GitHub About (description, website, topics) for REQ-DOCS-018.

## [1.0.1] - 2026-10-07

### Added

- Full unit coverage for the DI extension, bundle class, and remaining subscriber branches (PHP statements **100%**).
- `.scripts/coverage-check.sh` so CI enforces the 99% Clover gate.

### Fixed

- `.github/SECURITY.md` referenced the wrong product name (`OutboundUrlGuard`); now documents **HttpHardeningBundle**.
- `docs/SECURITY.md` expanded to the full threat model, mitigations, release checklist 12.4.1, and REQ-SEC-004 **Pass (good)** record.

### Changed

- README `## Documentation` links include Security, Changelog, and related guides.

## [1.0.0] - 2026-10-07

### Added

- Initial public release of **HTTP Hardening** (`nowo-tech/http-hardening-bundle`).
- `SecurityHeadersSubscriber`: nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy, CORP, and optional HSTS (skips loopback by default).
- `AnonymousSessionCookieStripSubscriber`: strip the session cookie on configured public GET/HEAD routes for anonymous users and set public Cache-Control.
- Flex recipe `nowo_http_hardening.yaml` with strip disabled by default.

[Unreleased]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.10.0...HEAD
[1.6.0]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.5.0...v1.6.0
[1.1.0]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.4...v1.1.0
[1.0.4]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/HttpHardeningBundle/releases/tag/v1.0.0
