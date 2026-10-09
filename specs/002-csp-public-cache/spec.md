# Feature specification — CSP nonce, public cache, canonical host, safe URLs

**Status**: Implemented — unreleased (target **v1.1.0**)  
**Last audited:** 2026-10-09  
**Extends:** [001-baseline](../001-baseline/spec.md)

## Summary

Opt-in response hardening ported from a production Symfony app and generalised:

- nonce-based **Content-Security-Policy** with nonce stamping, strict-nonce layouts, runtime source providers and Twig helpers;
- a **lint command** that keeps Twig templates compatible with strict-nonce layouts;
- **public Cache-Control** for anonymous public pages and crawler/PWA files, coexisting with the anonymous session strip;
- **canonical host** `www` → apex redirect;
- **safe internal redirect** / **safe href** helpers.

Config root: `nowo_http_hardening` (`csp`, `public_cache`, `canonical_host`, `safe_urls`).

## Goals

- Every new runtime behaviour is disabled by default (BC with v1.0.x)
- One shared nonce per main request in the request attribute `csp_nonce`
- Policy fully driven by config; debug-only relaxations never reach production
- HTML caching that never shares or revalidates a nonce'd body
- No coupling to application services (CDN origins come from tagged providers)

## Non-goals

- CSP violation report collection endpoint
- Per-route / per-path policy overrides (only path exclusion)
- HTML sanitisation of CMS content
- Shared-cache (CDN) caching of nonce'd HTML

## User scenarios

### US-CSP-01 — HTML gets a nonce-based policy

**Given** `csp.enabled: true` in prod  
**When** an HTML main response is sent  
**Then** `Content-Security-Policy` contains `script-src 'self' 'nonce-<n>'` and `style-src-elem 'self' 'nonce-<n>'`, no `'unsafe-eval'`, no `ws:`; `<n>` equals `request.attributes['csp_nonce']`

### US-CSP-02 — Legacy templates keep working

**Given** a layout without `data-csp-strict-nonce`  
**When** it contains `<script>…</script>` without a nonce  
**Then** the subscriber stamps `nonce="<n>"` on it (not on `src=` or non-JS `type=` scripts)

### US-CSP-03 — CMS layouts are not blessed

**Given** `<html data-csp-strict-nonce>` as the first `<html>` tag  
**When** CMS HTML injects `<script>`  
**Then** nothing is stamped and the browser blocks it

### US-CSP-04 — CI catches missing nonces

**Given** a template with `<style>.x{}</style>`  
**When** `nowo:http-hardening:lint-csp-nonces` runs  
**Then** it prints `file:line  <style>` and exits non-zero

### US-CACHE-01 — Anonymous page is browser-cacheable

**Given** `public_cache.enabled` and route `site_home` under a page prefix  
**When** an anonymous GET returns 200 without cookies  
**Then** `Cache-Control: max-age=120, must-revalidate, private`, `Vary: Cookie`, no ETag when a nonce was issued

### US-CACHE-02 — Strip and cache coexist

**Given** strip and public cache enabled for the same routes  
**When** the strip removes the session cookie and marks the page `public`  
**Then** the public cache subscriber (running later) normalises it to the private page policy

### US-HOST-01 — www goes to apex

**Given** `canonical_host.base_url: https://example.com`  
**When** `https://www.example.com/a?b=1` is requested  
**Then** a single 301 to `https://example.com/a?b=1` is returned before routing

## Functional requirements

### CSP

| ID | Requirement |
| ---- | ----------- |
| FR-CSP-001 | `csp.enabled` defaults to `false`; when false no CSP listener and no `CspNonceExtension` are registered |
| FR-CSP-002 | On the main `kernel.request` (priority 1024) store `base64(random_bytes(16))` in attribute `csp_nonce` unless already set |
| FR-CSP-003 | On the main `kernel.response` (priority -100) set the header only for HTML responses (`text/html` or `<html` in the first 512 bytes), never overwriting an existing header of the same name |
| FR-CSP-004 | Skip paths starting with `excluded_path_prefixes` (default `/_wdt`, `/_profiler`) |
| FR-CSP-005 | Emit directives in fixed order from config lists; append nonce (script-src, style-src-elem), debug sources, `*_extra`, provider sources; trim, dedupe, drop blanks; omit empty directives |
| FR-CSP-006 | Debug sources (`'unsafe-eval'`, style `'unsafe-inline'`, `ws: wss:`) only when `kernel.debug` and `debug_relaxations` |
| FR-CSP-007 | Stamp nonces on inline `<script>` (executable type, no `src`) and `<style>` without a nonce when `stamp_nonces` and the first `<html>` tag lacks `data-csp-strict-nonce`; streamed responses are not buffered |
| FR-CSP-008 | `report_only` switches the header to `Content-Security-Policy-Report-Only` |
| FR-CSP-009 | `CspSourceProviderInterface` services are autoconfigured with tag `nowo_http_hardening.csp_source_provider`; unknown directives are ignored |
| FR-CSP-010 | Twig `csp_nonce()` returns the current (else main) request nonce or `''`; `|csp_stamp` stamps trusted fragments |

### Lint command

| ID | Requirement |
| ---- | ----------- |
| FR-LINT-001 | `nowo:http-hardening:lint-csp-nonces [dirs...]`, default `templates/` relative to `kernel.project_dir`; absolute paths and single files accepted |
| FR-LINT-002 | Strip Twig comments (line numbers preserved); flag `<script>`/`<style>` with a non-blank body and no `nonce` attribute |
| FR-LINT-003 | Exempt scripts with `src=` or `type` JSON / ld+json / importmap / text/template |
| FR-LINT-004 | Exit `0` with no findings, `1` otherwise; registered only when `symfony/console` is installed |

### Public cache

| ID | Requirement |
| ---- | ----------- |
| FR-CACHE-001 | `public_cache.enabled` defaults to `false`; listener priority -1100 (after strip -1024) |
| FR-CACHE-002 | Only main GET/HEAD 200 responses on configured page / file routes, for non-`UserInterface` tokens, without `no-store` and without response cookies |
| FR-CACHE-003 | Pages: skip when the request carries the session or remember-me cookie unless upstream already made it `public`; set page policy and `Vary: Cookie`; drop ETag when attribute `csp_nonce` exists |
| FR-CACHE-004 | Files: skip when the request carries any cookie; set file policy |
| FR-CACHE-005 | An empty policy string disables that kind |

### Canonical host

| ID | Requirement |
| ---- | ----------- |
| FR-HOST-001 | `canonical_host.enabled` defaults to `false`; listener on `kernel.request` priority 2048, main requests only |
| FR-HOST-002 | Redirect only when the host equals `www.<apex of base_url>`; keep scheme, non-default port, path and query |
| FR-HOST-003 | No redirect for loopback / `*.localhost` / unparsable base URLs; `status_code` ∈ {301, 302, 307, 308} |

### Safe URLs

| ID | Requirement |
| ---- | ----------- |
| FR-SAFE-001 | `SafeInternalRedirect::resolve()` returns a same-app path or the fallback (rejects `//`, backslashes, `%5c`, control chars, one-level-decoded `//` / `\`, other hosts) |
| FR-SAFE-002 | `SafeInternalRedirect` is always registered as a service; `resolveForCurrentRequest()` falls back without a request |
| FR-SAFE-003 | `SafeHref::resolve()` allows relative paths, `http(s)` URLs and bare relative paths only |
| FR-SAFE-004 | `safe_urls.twig_functions` (default `false`) registers `safe_internal_path()` and `safe_href()` |

## Success criteria

| ID | Criterion |
| ---- | --------- |
| SC-01 | All `src/` files mapped in [code-inventory.md](code-inventory.md) or the baseline inventory |
| SC-02 | PHPUnit statement coverage of `src/` stays ≥99% (100% at time of writing) |
| SC-03 | `make cs-check`, `make phpstan`, `make rector-dry`, `make igor`, `make test-coverage` green |
| SC-04 | With default config the container registers no new listener |

## Validation commands

```bash
make test
make test-coverage
make phpstan
make igor
```

## Language

Public specifications and integrator documentation in this repository are written in **English**.
