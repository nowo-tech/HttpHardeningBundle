# Baseline specification — HTTP Hardening Bundle

**Status**: Implemented — `v1.0.3`  
**Last audited:** 2026-10-07  
**Aligned with:** public API / config through **v1.0.3**

## Summary

Symfony bundle that hardens HTTP **responses**: baseline security headers (set-if-missing) and an optional anonymous session-cookie strip on configured public GET/HEAD routes so CDNs and checkers see cacheable HTML.

Integrator-facing docs (English): [`README.md`](../../README.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md), [`docs/SECURITY.md`](../../docs/SECURITY.md), [`docs/UPGRADING.md`](../../docs/UPGRADING.md), [`docs/CHANGELOG.md`](../../docs/CHANGELOG.md).

Config root: `nowo_http_hardening`.

## Goals

- Apply nosniff, frame options, referrer policy, permissions policy, CORP, and optional HSTS when missing
- Skip HSTS on loopback / localhost by default
- Optionally strip the session cookie for anonymous users on listed public GET/HEAD routes
- Preserve non-session cookies and authenticated sessions
- Ship Flex recipe with strip disabled

## Non-goals

- Full Content-Security-Policy generation *(superseded: opt-in CSP added in [002-csp-public-cache](../002-csp-public-cache/spec.md))*
- WAF, TLS termination, authentication, or authorization
- HTML body sanitization / XSS filtering
- Admin UI or frontend assets (optional Twig helpers: see [002-csp-public-cache](../002-csp-public-cache/spec.md))
- Demos (none in this package)

## User scenarios

### US-01 — Public HTML carries hardening headers

**Given** a main HTTPS response without hardening headers  
**When** `SecurityHeadersSubscriber` runs  
**Then** nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy, and CORP are set, and HSTS is set on non-loopback hosts

### US-02 — Loopback HTTPS does not receive HSTS by default

**Given** `https://localhost/` (or `127.0.0.1` / `::1` / `*.localhost`)  
**When** headers are applied with default config  
**Then** HSTS is omitted; other hardening headers still apply

### US-03 — Anonymous public GET is CDN-friendly

**Given** strip enabled and a public route listed  
**When** an anonymous GET/HEAD responds with a session cookie  
**Then** the session cookie is removed, other cookies remain, and public Cache-Control may be set

### US-04 — Authenticated users keep the session

**Given** a token user implementing `UserInterface`  
**When** strip would otherwise apply  
**Then** the session cookie is kept

## Functional requirements

### Bundle / DI

| ID | Requirement |
| ---- | ----------- |
| FR-DI-001 | Config tree under `nowo_http_hardening` with `security_headers` and `anonymous_session_strip` |
| FR-DI-002 | Register `SecurityHeadersSubscriber` only when `security_headers.enabled` is true |
| FR-DI-003 | Register `AnonymousSessionCookieStripSubscriber` only when `anonymous_session_strip.enabled` is true |
| FR-DI-004 | Extension alias is `nowo_http_hardening` |
| FR-BUNDLE-001 | `NowoHttpHardeningBundle` exposes `NowoHttpHardeningExtension` |

### Security headers

| ID | Requirement |
| ---- | ----------- |
| FR-HDR-001 | Set X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, CORP when missing and non-empty |
| FR-HDR-002 | Never overwrite an existing response header of the same name |
| FR-HDR-003 | HSTS only on secure main requests when enabled and not already present |
| FR-HDR-004 | Skip HSTS on localhost / 127.0.0.1 / ::1 / `*.localhost` when `hsts_skip_loopback` is true |
| FR-HDR-005 | Ignore sub-requests |

### Anonymous session strip

| ID | Requirement |
| ---- | ----------- |
| FR-STRIP-001 | Default `enabled: false` |
| FR-STRIP-002 | Only GET and HEAD main requests |
| FR-STRIP-003 | Skip when token user is `UserInterface` |
| FR-STRIP-004 | Match `public_route_names` exactly or `public_route_name_prefixes` (empty prefix ignored) |
| FR-STRIP-005 | Remove only the configured session cookie name; re-emit other cookies |
| FR-STRIP-006 | When a session cookie was stripped and `public` Cache-Control is absent, set configured `cache_control` |

### Security docs

| ID | Requirement |
| ---- | ----------- |
| FR-SEC-001 | Ship `docs/SECURITY.md` (threat model + 12.4.1) and `.github/SECURITY.md` |
| FR-SEC-002 | REQ-SEC-004 Pass (good) / Low before tagging a release |

## Success criteria

| ID | Criterion |
| ---- | --------- |
| SC-01 | `find src -type f` count equals mapped rows in `code-inventory.md` (**5/5**) |
| SC-02 | PHPUnit covers `src/` at ≥99% statements (target 100%) |
| SC-03 | `make release-check` passes on the release commit |
| SC-04 | CI `ci.yml` green on default branch |

## Key entities

- Config trees: `security_headers`, `anonymous_session_strip`
- Services: `SecurityHeadersSubscriber`, `AnonymousSessionCookieStripSubscriber`

## Assumptions

- Host Symfony app provides HttpKernel; strip mode also needs Security token storage
- Header string values are operator-trusted configuration, not end-user input

## Validation commands

```bash
make test
make test-coverage
make phpstan
make igor
make release-check
```

## Traceability

See [code-inventory.md](code-inventory.md) and [docs/SPEC-DRIVEN-DEVELOPMENT.md](../../docs/SPEC-DRIVEN-DEVELOPMENT.md).

## Language

Public specifications and integrator documentation in this repository are written in **English**.
