# Upgrading

## From 1.0.0 to 1.0.1

No configuration or API changes. Safe to upgrade in place:

```bash
composer update nowo-tech/http-hardening-bundle
```

Review [docs/SECURITY.md](SECURITY.md) if you maintain a local security checklist — the threat model and 12.4.1 release items are now complete. Behaviour of security headers and anonymous session strip is unchanged.

## First install

```bash
composer require nowo-tech/http-hardening-bundle
```

Security headers are enabled by default. Anonymous session strip is **off** until you list public routes:

```yaml
# config/packages/nowo_http_hardening.yaml
nowo_http_hardening:
    security_headers:
        enabled: true
    anonymous_session_strip:
        enabled: true
        session_cookie_name: '%env(default::APP_SESSION_COOKIE_NAME)%'
        # Prefer a parameter, e.g. '%app.session_cookie_name%'
        public_route_name_prefixes: ['site_', 'legal_']
        public_route_names:
            - nowo_seo_kit_robots
            - nowo_seo_kit_sitemap
            - nowo_pwa_manifest
            - nowo_pwa_service_worker
```

Requires `security.token_storage` in the host when strip is enabled. Do not enable strip on routes that still need an anonymous session (login CSRF, carts, multi-step forms). See [SECURITY.md](SECURITY.md).
