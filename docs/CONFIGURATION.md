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
| `enabled` | `false` | Uses `security.token_storage` when present (optional; without it every visitor is anonymous) |
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
| `nonce_free_style_path_prefixes` | `[]` | (v1.2+) Paths whose `style-src-elem` gets `'unsafe-inline'` **instead of** the nonce — routes that mount editors injecting `<style>` at runtime (CKEditor 5). Segment-aware prefix (`/admin/legal` matches `/admin/legal/…`, not `/admin/legalese`); `script-src` keeps its nonce |

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

Cacheable `Cache-Control` for anonymous GET/HEAD 200 responses on listed routes (priority -1100, after the session listener and `anonymous_session_strip`). Off by default; uses `security.token_storage` when present (optional).

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

## no_store

`Cache-Control: no-store, private` on sensitive pages (credentials, API keys, exports). Off by default. Registers `NoStoreSubscriber` (`kernel.response` priority -1200) only when enabled.

| Key | Default | Notes |
|-----|---------|-------|
| `enabled` | `false` | |
| `path_patterns` | `[]` | PCRE patterns with delimiters, matched against `Request::getPathInfo()`, e.g. `'#^/admin(?:/\|$)#'`. Validated at container build: an invalid pattern throws `InvalidConfigurationException` |
| `routes` | `[]` | Exact route names (`_route` attribute) |

On a matching **main** request `Cache-Control` is replaced with `no-store, private` and `ETag` / `Last-Modified` are removed. Sub-requests are ignored. It runs after the session listener (-1000), `anonymous_session_strip` (-1024) and `public_cache` (-1100), so `no-store` always wins.

## trusted_hosts

Env var processor `%env(nowo_trusted_hosts:SYMFONY_TRUSTED_HOSTS)%` so production never runs without a Host allowlist:

```yaml
# config/packages/framework.yaml
framework:
    trusted_hosts: '%env(nowo_trusted_hosts:SYMFONY_TRUSTED_HOSTS)%'

# config/packages/nowo_http_hardening.yaml
nowo_http_hardening:
    trusted_hosts:
        fallback_env: TRUSTED_HOSTS          # devops-written regex, used when SYMFONY_TRUSTED_HOSTS is empty
        public_url_env: DEFAULT_URI          # host derived from this URL outside local_environments
        internal_hosts: [app-php, localhost, 127.0.0.1]   # container alias + loopback probes (plain names)
        local_environments: [dev, test]      # keep the list empty (any Host) here when nothing is set
```

Resolution: the named variable → `fallback_env` → (outside `local_environments`) `^(?:<public host>|<internal hosts>)$` → empty. A request with any other `Host` answers 400 (Symfony). Prefer this over `%env(default::SYMFONY_TRUSTED_HOSTS)%`, which leaves the list empty in production when the variable is missing.

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

## web_profiler

(v1.5+) Fix for WebProfilerBundle in long-running workers. No-op when WebProfilerBundle is not installed or not enabled (prod).

| Key | Default | Notes |
|-----|---------|-------|
| `isolate_csp_handler` | `true` | Make `web_profiler.csp.handler` non-shared (compiler pass `WebProfilerCspHandlerPass`) |

Why: WebProfilerBundle's `ContentSecurityPolicyHandler` keeps a `cspDisabled` flag that `ProfilerController` sets on `/_wdt/styles` and `/_profiler` pages and never resets. The service is shared, so the toolbar listener uses the same instance. In FrankenPHP worker mode (or any runtime that keeps the kernel between requests), once a worker has served a profiler request every later page loses its `Content-Security-Policy` header and the toolbar's inline `<script>` renders without a nonce. Non-shared, each consumer gets its own handler. The profiler paths never get a CSP from this bundle anyway (`csp.excluded_path_prefixes`). Set it to `false` only if you decorate or replace that service yourself.
