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
