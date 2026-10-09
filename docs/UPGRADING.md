# Upgrading

## From 1.0.x to 1.1.0

No breaking changes. Every new feature is **off by default**; existing configuration keeps working unchanged. Only the `SafeInternalRedirect` service, the `InlineNonceLinter` service and (with `symfony/console`) the `nowo:http-hardening:lint-csp-nonces` command are registered unconditionally — they are stateless and have no effect until used.

```bash
composer update nowo-tech/http-hardening-bundle
```

Adopt the new features one at a time:

1. **Lint first.** Run `php bin/console nowo:http-hardening:lint-csp-nonces` and add `nonce="{{ csp_nonce() }}"` to the reported blocks. Add the command to CI.
2. **CSP in report-only.** Enable `csp` with `report_only: true`, list third-party origins in the `*_extra` keys (or a `CspSourceProviderInterface` service), watch the browser console, then set `report_only: false`.
   - If your app already ships its own CSP listener / `csp_nonce()` Twig function, remove it (or keep `csp.enabled: false`): the bundle never overwrites an existing `Content-Security-Policy` header, but two Twig extensions defining `csp_nonce` would shadow each other.
   - The nonce request attribute is `csp_nonce`. If your app used another name (e.g. `_shell_csp_nonce`), switch templates to `csp_nonce()` / `app.request.attributes.get('csp_nonce')`.
   - Layouts that print CMS HTML should add `data-csp-strict-nonce` on `<html>` so injected `<script>` is not stamped.
3. **Public cache.** Enable `public_cache` with the same session cookie name and route prefixes you give `anonymous_session_strip`, plus `file_route_names` for sitemap / robots / manifest. Pages become `private, max-age=120, must-revalidate` + `Vary: Cookie` (the strip's `public` is normalised for pages).
4. **Canonical host.** Enable `canonical_host` with `base_url: '%env(SITE_PUBLIC_BASE_URL)%'` once the edge serves a valid certificate for `www`.
5. **Safe URLs.** Inject `Nowo\HttpHardeningBundle\Http\SafeInternalRedirect` for `?next=` / `_target_path` handling; set `safe_urls.twig_functions: true` for `safe_internal_path()` / `safe_href()` (remove app-level functions with the same names first).

See [CONFIGURATION.md](CONFIGURATION.md) and [USAGE.md](USAGE.md).

## From 1.0.3 to 1.0.4

No breaking changes. No application upgrade steps (dev dependency lockfile refresh only):

```bash
composer update nowo-tech/http-hardening-bundle
```

## From 1.0.2 to 1.0.3

No configuration or API changes. Spec/docs alignment only:

```bash
composer update nowo-tech/http-hardening-bundle
```

## From 1.0.1 to 1.0.2

No configuration or API changes. Documentation and Spec Kit baseline only:

```bash
composer update nowo-tech/http-hardening-bundle
```

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
