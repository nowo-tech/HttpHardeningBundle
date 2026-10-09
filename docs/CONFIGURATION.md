# Configuration

Root key: `nowo_http_hardening`.

## security_headers

| Key | Default | Notes |
|-----|---------|-------|
| `enabled` | `true` | Register the response subscriber |
| `x_content_type_options` | `nosniff` | Empty string skips the header |
| `x_frame_options` | `SAMEORIGIN` | |
| `referrer_policy` | `strict-origin-when-cross-origin` | |
| `permissions_policy` | `camera=(), microphone=(), geolocation=()` | |
| `cross_origin_resource_policy` | `same-origin` | |
| `hsts_enabled` | `true` | |
| `hsts` | `max-age=31536000; includeSubDomains` | Do not add `preload` until the checklist is complete |
| `hsts_skip_loopback` | `true` | Skip HSTS on localhost / 127.0.0.1 / ::1 / `*.localhost` |

Existing response headers are never overwritten.

## anonymous_session_strip

| Key | Default | Notes |
|-----|---------|-------|
| `enabled` | `false` | Requires `security.token_storage` |
| `session_cookie_name` | `PHPSESSID` | Match the host session cookie |
| `public_route_name_prefixes` | `[]` | e.g. `site_`, `legal_` |
| `public_route_names` | `[]` | Exact route names |
| `cache_control` | `public, max-age=120, must-revalidate` | Applied when the session cookie was stripped and `public` is absent |

Authenticated users (`UserInterface`) always keep the session cookie. Other cookies (consent, etc.) are preserved.

Do not enable strip on routes that still need an anonymous session. See [SECURITY.md](SECURITY.md).

## csp

Content-Security-Policy on HTML main responses. Off by default.

| Key | Default | Notes |
|-----|---------|-------|
| `enabled` | `false` | Registers `ContentSecurityPolicySubscriber` (request 1024 / response -100) and, with `twig/twig`, the `csp_nonce()` / `\|csp_stamp` Twig helpers |
| `report_only` | `false` | Send `Content-Security-Policy-Report-Only` instead (recommended for the first rollout) |
| `stamp_nonces` | `true` | Add the nonce to inline `<script>` / `<style>` without one, unless `<html>` carries `data-csp-strict-nonce` |
| `debug_relaxations` | `true` | With `kernel.debug`: `'unsafe-eval'` on `script-src`, `'unsafe-inline'` on `style-src-elem`, `ws: wss:` on `connect-src` |
| `excluded_path_prefixes` | `['/_wdt', '/_profiler']` | Paths that never receive the header |

Directive sources (each is a list; setting it **replaces** the default; `[]` omits the directive). Every directive also has a `<key>_extra` list (default `[]`) that is **appended** — use it for env-driven origins (Sentry, CDNs) without restating the defaults.

| Key | Directive | Default |
|-----|-----------|---------|
| `default_src` | `default-src` | `'self'` |
| `base_uri` | `base-uri` | `'self'` |
| `object_src` | `object-src` | `'none'` |
| `frame_ancestors` | `frame-ancestors` | `'self'` |
| `form_action` | `form-action` | `'self'` |
| `font_src` | `font-src` | `'self' data:` |
| `img_src` | `img-src` | `'self' data: blob:` |
| `frame_src` | `frame-src` | `'self'` |
| `style_src_elem` | `style-src-elem` | `'self'` + nonce |
| `style_src_attr` | `style-src-attr` | `'unsafe-inline'` (CSSOM / `style=""`) |
| `script_src` | `script-src` | `'self'` + nonce |
| `connect_src` | `connect-src` | `'self'` |
| `worker_src` | `worker-src` | `'self' blob:` |
| `manifest_src` | `manifest-src` | `'self'` |

Keyword sources must keep their single quotes in YAML: `["'self'"]`.

An existing header of the same name (set by a controller or another listener) is never overwritten. Services implementing `Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface` are autoconfigured (tag `nowo_http_hardening.csp_source_provider`) and may add sources per request.

## public_cache

Cacheable `Cache-Control` for anonymous GET/HEAD 200 responses on listed routes (priority -1100, after the session listener and `anonymous_session_strip`). Off by default; requires `security.token_storage`.

| Key | Default | Notes |
|-----|---------|-------|
| `enabled` | `false` | |
| `page_route_name_prefixes` | `[]` | HTML pages, e.g. `site_`, `legal_` |
| `page_route_names` | `[]` | Exact page route names |
| `page_cache_control` | `private, max-age=120, must-revalidate` | Pages also get `Vary: Cookie`; an upstream `public` (strip) is normalised to this |
| `file_route_names` | `[]` | Crawler / PWA files (sitemap, robots, llms.txt, manifest) |
| `file_cache_control` | `public, max-age=3600, must-revalidate` | Only for cookie-less requests |
| `session_cookie_name` | `PHPSESSID` | A request carrying it opts out (flashes) |
| `remember_me_cookie_name` | `REMEMBERME` | A request carrying it opts out; `''` disables |

Signed-in users, responses that set cookies, non-200 responses and `no-store` are left alone. Pages lose their ETag when a CSP nonce was issued (a 304 would reuse an old nonce under a new header). An empty policy string disables that kind.

## canonical_host

| Key | Default | Notes |
|-----|---------|-------|
| `enabled` | `false` | Registers `CanonicalHostRedirectSubscriber` (request priority 2048) |
| `base_url` | `''` | Public base URL, e.g. `'%env(SITE_PUBLIC_BASE_URL)%'`; scheme and non-default port are kept |
| `status_code` | `301` | One of 301, 302, 307, 308 |

Only `www.<apex>` is redirected (to the apex, path + query kept). Loopback / `*.localhost` / unparsable base URLs disable it. A `www.` base URL is reduced to its apex.

## safe_urls

| Key | Default | Notes |
|-----|---------|-------|
| `twig_functions` | `false` | Register `safe_internal_path(target, fallback = '/')` and `safe_href(href)` (requires `twig/twig`) |

The `Nowo\HttpHardeningBundle\Http\SafeInternalRedirect` service is always registered (stateless).
