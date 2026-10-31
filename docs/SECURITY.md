# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Mitigations](#mitigations)
- [What the host must still do](#what-the-host-must-still-do)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Dependencies and updates](#dependencies-and-updates)
- [Permissions and exposure](#permissions-and-exposure)
- [Reporting](#reporting)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit](#ai-security-audit)

## Scope

This bundle hardens HTTP **responses**:

- Sets baseline security headers when missing (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Resource-Policy`, optional HSTS).
- Optionally strips the session cookie on configured **anonymous** public GET/HEAD routes so CDNs and checkers see cacheable HTML.
- Optionally forces `Cache-Control: no-store, private` (no `ETag` / `Last-Modified`) on configured sensitive paths / routes (`no_store`).
- Optionally removes the tenant **existence oracle** (`tenant_existence_mask`): an access denial on a configured tenant path answers 404 — like an unknown id — for a signed-in user with no access at all to that tenant (decided by the host's `TenantAccessCheckerInterface`).

It does **not** implement a full Content-Security-Policy, WAF, TLS termination, authentication, authorization, or XSS sanitization of HTML bodies. Those remain host / edge responsibilities.

## Attack surface

- Bundle configuration under `nowo_http_hardening` (header string values, HSTS flags, public route name lists).
- Kernel `ResponseEvent` on main requests (no controllers, forms, CLI, admin UI, or Twig/JS assets).
- Optional dependency on `security.token_storage` when `anonymous_session_strip`, `public_cache` or `tenant_existence_mask` is enabled; without it every visitor is treated as anonymous.
- Kernel `ExceptionEvent` (`tenant_existence_mask` only): replaces access-denied throwables, never adds one; the access decision is the host's.
- No user-controlled request body parsing; header values come from developer-owned config only.

## Threat model

| Threat | Risk |
| --- | --- |
| Clickjacking / MIME sniffing / referrer leakage without baseline headers | Medium if the host never sets them |
| Accidental HSTS on loopback / local demos | Low — mitigated by `hsts_skip_loopback` (default true) |
| Overwriting stronger headers already set by the app or edge | Low — subscribers use set-if-missing |
| Stripping the session cookie on routes that still need anonymous session (CSRF forms, carts) | Medium if the host mis-lists public routes |
| Credential pages stored by the browser or a proxy (back button, disk cache) | Medium if the host lists no `no_store` paths — mitigated by `no_store` (runs last, overrides every cache policy) |
| Tenant enumeration through 403 vs 404 on `/tenants/{id}/…` URLs (existence oracle) | Medium on multi-tenant apps — mitigated by `tenant_existence_mask` for the tenant segment; owner-scoped lookups inside a tenant must 404 at entity level (host) |
| Mask hiding a legitimate 403 from a member | Low — only users for whom the host checker returns "no access at all" are masked; members keep 403 |
| Stripping cookies for authenticated users | Low — `UserInterface` tokens keep the session cookie |
| Pre-auth request floods on public hooks / token APIs (credential lookups, brute force) | Medium on public endpoints — mitigated by the opt-in `rate_limit` rules (429 before the firewall, per hashed client IP); host must configure `framework.trusted_proxies` so `getClientIp()` is the real client |
| Rate limit bypass or shared bucket behind a proxy | Medium if trusted proxies are missing (every client shares the proxy IP) or spoofable (`X-Forwarded-For` trusted from anywhere) — host configuration |
| Header injection via config | Low — config is operator-trusted, not end-user input |
| XSS, CSRF, SSRF, SQL injection, path traversal, deserialization, RCE | Not applicable — no HTML render, no outbound HTTP, no file/CLI surface |

## Mitigations

- Security headers are enabled by default with conservative values (`nosniff`, `SAMEORIGIN`, `strict-origin-when-cross-origin`, restrictive Permissions-Policy, `CORP: same-origin`).
- Empty header config strings skip that header (no empty `Set-Cookie`-style footgun).
- Existing response headers are never overwritten (`setIfMissing`).
- HSTS is sent only on HTTPS; loopback / `localhost` / `*.localhost` are skipped by default.
- Anonymous session strip is **opt-in** (`enabled: false`). It runs only for GET/HEAD, only when the token user is not a `UserInterface`, and only for explicitly configured route names / prefixes.
- Non-session cookies (e.g. consent) are preserved when the session cookie is removed.
- `tenant_existence_mask` is **opt-in**, masks only signed-in users on configured patterns, and throws a bare `NotFoundHttpException` without `previous` (the firewall would otherwise walk the chain back to a 403). Patterns without a capture group fail the container build.
- Flex recipe keeps strip disabled and documents example public SEO/PWA routes as comments only.

## What the host must still do

- Do not enable `anonymous_session_strip` on routes that rely on an anonymous session (login CSRF, multi-step forms, carts).
- Align `session_cookie_name` with the real Symfony session cookie name.
- Supply a real CSP / COOP / COEP policy if the product needs one; this bundle does not invent a CSP.
- Keep edge TLS and HSTS preload decisions at the reverse proxy when that is the source of truth; PHP still sets HSTS when missing so an older edge cannot drop it.
- With `tenant_existence_mask`, make `hasAnyAccess()` return `true` for every relationship that already reveals the tenant (membership, share grant, admin override), and return 404 for out-of-tenant entity ids inside a tenant (owner-scoped queries).
- Install `symfony/security-bundle` (token storage) before enabling session strip on an app with signed-in users; otherwise their session cookie is stripped on the listed routes too.

## Secrets and cryptography

The bundle does not implement cryptography and does not embed secrets. Do not put tokens or private keys in committed config.

## Logging

Subscribers do not log request URLs, cookies, tokens, or header values. There is no `error_log` / `dump` on the response path.

## Dependencies and updates

Runtime dependencies are Symfony components only. `composer audit --locked` runs in CI. Security updates are applied with Dependabot (Composer and GitHub Actions) and shipped as patch or minor releases.

## Permissions and exposure

No HTTP routes or admin UI are registered. Access control is entirely the host application's. The public surface is kernel event subscribers only.

## Reporting

Report vulnerabilities to **hectorfranco@nowo.tech**. Do not open a public issue for an unfixed bypass. See also [.github/SECURITY.md](../.github/SECURITY.md).

## Release security checklist (12.4.1)

Confirm before each tag:

| Item | Status |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` exist and stay in English | Required |
| `.env` is gitignored; no secrets in the tree | Required |
| Flex recipe defaults are safe (`security_headers` on; `anonymous_session_strip` off) | Required |
| `no_store` stays off by default; invalid path patterns fail the container build | Required |
| `rate_limit` stays off by default; invalid patterns, relative prefixes and rules without paths fail the container build | Required |
| `tenant_existence_mask` stays off by default; patterns without a capture group fail the container build; the 404 carries no `previous` | Required |
| `web_profiler.isolate_csp_handler` only touches `web_profiler.csp.handler` when it exists (dev/test) | Required |
| Config is operator-trusted; no HTML output to escape | Required |
| `composer audit` reviewed in CI | Required |
| No secret logging | Required |
| No custom cryptography | N/A |
| No public endpoints / admin UI | N/A |
| DoS / resource limits | `BoundedRequestBody` caps raw body reads (cap + 1 bytes max); opt-in `rate_limit` (429 + `Retry-After`, buckets in a PSR-6 pool keyed by the `xxh128` hash of the client IP, never the raw IP); no outbound I/O or subprocesses |
| AI security audit (REQ-SEC-004) | Pass (good), 2026-10-07 (reconfirmed for **v1.0.1**; `no_store` reviewed for **v1.4.0**, 2026-10-13; `BoundedRequestBody` and the profiler CSP pass reviewed for **v1.5.0**, 2026-10-14; `tenant_existence_mask` reviewed for **v1.7.0**, 2026-10-16). Overall risk Low. No open Critical or High findings. |

## AI security audit

Static review of `src/`, the Flex recipe, and these security docs on **2026-10-07** (Cursor security-review + full-package pass). Reconfirmed for **v1.0.1** (docs + coverage only; no behaviour change). **v1.4.0** (2026-10-13): `NoStoreSubscriber` only removes cacheability headers; patterns are operator-owned config compiled at container build (no request-controlled regex). **v1.5.0** (2026-10-14): `BoundedRequestBody` rejects a declared oversize `Content-Length` before reading and never reads more than cap + 1 bytes; `WebProfilerCspHandlerPass` only changes the dev-only profiler handler to non-shared, which restores the CSP header in worker mode. **v1.7.0** (2026-10-16): `TenantExistenceMaskSubscriber` only downgrades an existing access denial to 404 for signed-in users the host checker reports as having no access; it never grants access, never masks anonymous users, and drops the exception chain so the firewall cannot re-raise the 403.

- **Grade:** Pass (good)
- **Risk:** Low
- **Method:** Cursor security-review / full-package static pass against `BUNDLES_SECURITY_ANALYSIS.md` §1
- **Residuals:** none in package code. Host must not list session-dependent anonymous routes under `public_route_*`; CSP remains host/edge-owned. Those are documented duties, not open findings in this package.

The monorepo report `BUNDLES_SECURITY_ANALYSIS.md` records the same grade (Appendix AB).
