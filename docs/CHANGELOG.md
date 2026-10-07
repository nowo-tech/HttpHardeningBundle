# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/nowo-tech/HttpHardeningBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/HttpHardeningBundle/releases/tag/v1.0.0
