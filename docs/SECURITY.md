# Security

## Scope

This bundle hardens HTTP **responses**:

- Sets baseline security headers when missing (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Resource-Policy`, optional HSTS).
- Optionally strips the session cookie on configured **anonymous** public GET/HEAD routes so CDNs and checkers see cacheable HTML.

It does **not** implement a full Content-Security-Policy, WAF, TLS termination, authentication, authorization, or XSS sanitization of HTML bodies. Those remain host / edge responsibilities.

## Attack surface

- Bundle configuration under `nowo_http_hardening` (header string values, HSTS flags, public route name lists).
- Kernel `ResponseEvent` on main requests (no controllers, forms, CLI, admin UI, or Twig/JS assets).
- Optional dependency on `security.token_storage` when `anonymous_session_strip.enabled` is true.
- No user-controlled request body parsing; header values come from developer-owned config only.

## Threat model

| Threat | Risk |
| --- | --- |
| Clickjacking / MIME sniffing / referrer leakage without baseline headers | Medium if the host never sets them |
| Accidental HSTS on loopback / local demos | Low — mitigated by `hsts_skip_loopback` (default true) |
| Overwriting stronger headers already set by the app or edge | Low — subscribers use set-if-missing |
| Stripping the session cookie on routes that still need anonymous session (CSRF forms, carts) | Medium if the host mis-lists public routes |
| Stripping cookies for authenticated users | Low — `UserInterface` tokens keep the session cookie |
| Header injection via config | Low — config is operator-trusted, not end-user input |
| XSS, CSRF, SSRF, SQL injection, path traversal, deserialization, RCE | Not applicable — no HTML render, no outbound HTTP, no file/CLI surface |

## Mitigations

- Security headers are enabled by default with conservative values (`nosniff`, `SAMEORIGIN`, `strict-origin-when-cross-origin`, restrictive Permissions-Policy, `CORP: same-origin`).
- Empty header config strings skip that header (no empty `Set-Cookie`-style footgun).
- Existing response headers are never overwritten (`setIfMissing`).
- HSTS is sent only on HTTPS; loopback / `localhost` / `*.localhost` are skipped by default.
- Anonymous session strip is **opt-in** (`enabled: false`). It runs only for GET/HEAD, only when the token user is not a `UserInterface`, and only for explicitly configured route names / prefixes.
- Non-session cookies (e.g. consent) are preserved when the session cookie is removed.
- Flex recipe keeps strip disabled and documents example public SEO/PWA routes as comments only.

## What the host must still do

- Do not enable `anonymous_session_strip` on routes that rely on an anonymous session (login CSRF, multi-step forms, carts).
- Align `session_cookie_name` with the real Symfony session cookie name.
- Supply a real CSP / COOP / COEP policy if the product needs one; this bundle does not invent a CSP.
- Keep edge TLS and HSTS preload decisions at the reverse proxy when that is the source of truth; PHP still sets HSTS when missing so an older edge cannot drop it.
- Require `symfony/security-bundle` (token storage) before enabling session strip.

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
| Config is operator-trusted; no HTML output to escape | Required |
| `composer audit` reviewed in CI | Required |
| No secret logging | Required |
| No custom cryptography | N/A |
| No public endpoints / admin UI | N/A |
| DoS / resource limits | N/A (no outbound I/O or subprocesses) |
| AI security audit (REQ-SEC-004) | Pass (good), 2026-10-07 (reconfirmed for **v1.0.1**). Overall risk Low. No open Critical or High findings. |

## AI security audit

Static review of `src/`, the Flex recipe, and these security docs on **2026-10-07** (Cursor security-review + full-package pass). Reconfirmed for **v1.0.1** (docs + coverage only; no behaviour change).

- **Grade:** Pass (good)
- **Risk:** Low
- **Method:** Cursor security-review / full-package static pass against `BUNDLES_SECURITY_ANALYSIS.md` §1
- **Residuals:** none in package code. Host must not list session-dependent anonymous routes under `public_route_*`; CSP remains host/edge-owned. Those are documented duties, not open findings in this package.

The monorepo report `BUNDLES_SECURITY_ANALYSIS.md` records the same grade (Appendix AB).
