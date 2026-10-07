# Upgrading

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

Requires `security.token_storage` in the host when strip is enabled.
