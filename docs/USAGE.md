# Usage

After installation, security headers apply on every main response when `security_headers.enabled` is true (default).

## Security headers

No code calls are required. Tune values under `nowo_http_hardening.security_headers` in [CONFIGURATION.md](CONFIGURATION.md). Existing response headers are never overwritten.

## Anonymous session strip

Opt in only for public GET/HEAD routes that must stay cacheable for anonymous visitors:

```yaml
nowo_http_hardening:
    anonymous_session_strip:
        enabled: true
        session_cookie_name: '%app.session_cookie_name%'
        public_route_name_prefixes: ['site_', 'legal_']
        public_route_names:
            - nowo_seo_kit_robots
            - nowo_seo_kit_sitemap
```

Authenticated users (`UserInterface`) keep the session cookie. Other cookies (consent, etc.) are preserved.

Uses `security.token_storage` when present to keep the cookie for signed-in users; without SecurityBundle every visitor is treated as anonymous. Do not list routes that still need an anonymous session (login CSRF, carts). See [SECURITY.md](SECURITY.md).

## Content-Security-Policy

```yaml
nowo_http_hardening:
    csp:
        enabled: true
        report_only: '%env(bool:CSP_REPORT_ONLY)%'   # start in report-only
        connect_src_extra: ['https://*.ingest.sentry.io']
        img_src_extra: ['https://images.example-cdn.com']
```

The nonce lives in the request attribute **`csp_nonce`** (shared convention of the nowo-tech kits: `app.request.attributes.get('csp_nonce')`). In Twig:

```twig
<script nonce="{{ csp_nonce() }}">window.app = {};</script>
<style nonce="{{ csp_nonce() }}">.hero { … }</style>
{{ marketing_snippet|csp_stamp }}   {# trusted third-party fragments only #}
```

### Strict-nonce layouts

By default the subscriber stamps the nonce on every inline `<script>` / `<style>` without one, so existing templates keep working. Stamping cannot tell template markup from injected markup: on layouts that print CMS / editor HTML, opt out and nonce every block explicitly:

```twig
<html lang="{{ app.request.locale }}" data-csp-strict-nonce>
```

Only the first `<html>` tag counts. Never pipe CMS / user HTML through `|csp_stamp`.

### Lint templates in CI

```bash
php bin/console nowo:http-hardening:lint-csp-nonces            # templates/
php bin/console nowo:http-hardening:lint-csp-nonces templates/ src/Kit/templates
```

Exit code is non-zero when an inline `<script>` / `<style>` with a body has no `nonce`. Twig comments are ignored; `src=` scripts, JSON / JSON-LD / importmap / `text/template` islands and empty blocks are exempt.

### Runtime sources

```php
use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Symfony\Component\HttpFoundation\Request;

final class ImageCdnCspSources implements CspSourceProviderInterface
{
    public function __construct(private readonly ImageCdnSettings $settings) {}

    public function getSources(Request $request): array
    {
        $origin = $this->settings->activeOrigin(); // e.g. https://ik.imagekit.io
        return null === $origin ? [] : ['img-src' => [$origin]];
    }
}
```

With autoconfiguration on, the class is tagged automatically.

## Public cache

```yaml
nowo_http_hardening:
    anonymous_session_strip:
        enabled: true
        session_cookie_name: '%app.session_cookie_name%'
        public_route_name_prefixes: ['site_', 'legal_']
    public_cache:
        enabled: true
        session_cookie_name: '%app.session_cookie_name%'
        page_route_name_prefixes: ['site_', 'legal_']
        file_route_names:
            - nowo_seo_kit_sitemap
            - nowo_seo_kit_robots
            - nowo_pwa_manifest
```

Order on `kernel.response` (higher priority first): security headers (-80) → CSP (-100) → Symfony session listener (-1000) → strip (-1024) → public cache (-1100). Pages end up `private, max-age=120, must-revalidate` with `Vary: Cookie`, even when the strip made them `public` — a per-response nonce must not be shared by a CDN.

## No-store paths

```yaml
nowo_http_hardening:
    no_store:
        enabled: true
        path_patterns:
            - '#^/(?:admin|settings|account)(?:/|$)#'
            - '#^/projects/[^/]+/(?:keys|settings)(?:/|$)#'
        routes: [app_export_config]
```

`/settings/mailer` → `Cache-Control: no-store, private`, no `ETag`, no `Last-Modified`. `/administrator` and `/projects/abc` are left alone. Anchor patterns (`^`) and end them on `(?:/|$)` to avoid matching look-alike paths.

Full `kernel.response` order: security headers (-80) → CSP (-100) → session listener (-1000) → strip (-1024) → public cache (-1100) → no-store (-1200). A route listed both in `public_cache` and `no_store` ends up `no-store`.

## Canonical host

```yaml
nowo_http_hardening:
    canonical_host:
        enabled: true
        base_url: '%env(SITE_PUBLIC_BASE_URL)%'   # https://example.com
```

`https://www.example.com/contact?x=1` → `301 https://example.com/contact?x=1`. The edge must still serve a valid certificate for `www`.

## Bounded request body

For endpoints that read a raw body (webhooks, ingest, JSON APIs), read it with a hard byte cap instead of `$request->getContent()`, which buffers the whole body first:

```php
use Nowo\HttpHardeningBundle\Http\BoundedRequestBody;
use Nowo\HttpHardeningBundle\Http\Exception\RequestBodyTooLargeException;

$body = BoundedRequestBody::read($request, 1_048_576); // throws RequestBodyTooLargeException (413)

// or, to build your own response:
$body = BoundedRequestBody::tryRead($request, 1_048_576);
if (null === $body) {
    return new Response('payload too large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
}
```

A `Content-Length` above the cap is rejected before a byte is read. Without one (chunked transfer) or with an understated one, at most cap + 1 bytes are read. Uncaught, `RequestBodyTooLargeException` is an `HttpException`, so Symfony renders a 413. `getMaxBytes()` and `isDeclared()` (rejected on `Content-Length`) help with logging. A cap below 1 means 1. Keep an edge limit too (Caddy `request_body`, nginx `client_max_body_size`); multipart forms are buffered by PHP before the kernel runs.

## Web Profiler in worker mode

Nothing to do: in dev the bundle makes WebProfilerBundle's `web_profiler.csp.handler` non-shared, so the CSP header and toolbar nonces survive `/_wdt` and `/_profiler` requests in FrankenPHP worker mode. Remove any host override of that service (`shared: false` in `when@dev`). See [Configuration — web_profiler](CONFIGURATION.md#web_profiler).

## Safe internal redirects

```php
use Nowo\HttpHardeningBundle\Http\SafeInternalRedirect;

$target = SafeInternalRedirect::resolve($request, (string) $request->query->get('next'), '/');
// or the autowirable service: $safeInternalRedirect->resolveForCurrentRequest($next, '/')
```

Absolute same-host URLs are reduced to path + query; protocol-relative URLs, backslashes, encoded separators and control characters fall back. With `safe_urls.twig_functions: true`:

```twig
<a href="{{ safe_internal_path(app.request.query.get('next'), '/') }}">Back</a>
{% set href = safe_href(block.link) %}{% if href %}<a href="{{ href }}">…</a>{% endif %}
```

## Bounded JSON uploads

```php
use Nowo\HttpHardeningBundle\Http\Exception\JsonUploadException;
use Nowo\HttpHardeningBundle\Http\JsonUpload;

try {
    $payload = JsonUpload::decodeObject($form->get('file')->getData(), maxBytes: 2_097_152);
} catch (JsonUploadException $e) {
    $this->addFlash('error', 'import.error.'.$e->reason); // missing_file | too_large | invalid_json
}
```

The declared upload size is checked first and the file is read at most to the cap + 1 byte, so a large upload never fills worker memory; only a JSON **object** is accepted.

