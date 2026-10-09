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

Requires `security.token_storage` in the host application. Do not list routes that still need an anonymous session (login CSRF, carts). See [SECURITY.md](SECURITY.md).

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

## Canonical host

```yaml
nowo_http_hardening:
    canonical_host:
        enabled: true
        base_url: '%env(SITE_PUBLIC_BASE_URL)%'   # https://example.com
```

`https://www.example.com/contact?x=1` → `301 https://example.com/contact?x=1`. The edge must still serve a valid certificate for `www`.

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
