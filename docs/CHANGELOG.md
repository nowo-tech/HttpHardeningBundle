# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
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

[Unreleased]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.4...v1.1.0
[1.0.4]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/HttpHardeningBundle/releases/tag/v1.0.0
