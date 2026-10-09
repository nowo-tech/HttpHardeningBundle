# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
