# Code inventory — baseline (100% of production `src/`)

**Last audited:** 2026-11-16  
**Aligned with:** **v1.10.0**

Canonical inventory: every file tracked under `src/` (audit: `git ls-files src | wc -l` → **44**), including the files added by the feature specs [002](../002-csp-public-cache/code-inventory.md)–[006](../006-tenant-existence-mask/code-inventory.md) (which keep their own per-feature deltas). Tests and tooling are out of scope.

## Bundle / DI

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/NowoHttpHardeningBundle.php` | Bundle / DI | FR-BUNDLE-001, FR-WP-002 |
| `src/DependencyInjection/Configuration.php` | Bundle / DI | FR-DI-001, FR-STRIP-001, FR-CSP-001, FR-CACHE-001, FR-HOST-001, FR-NS-001, FR-WP-001, FR-TM-001, FR-TH-001 |
| `src/DependencyInjection/NowoHttpHardeningExtension.php` | Bundle / DI | FR-DI-002, FR-DI-003, FR-DI-004, FR-CSP-009, FR-LINT-004, FR-NS-004, FR-TM-008 |
| `src/DependencyInjection/Compiler/WebProfilerCspHandlerPass.php` | Profiler CSP handler (005) | FR-WP-002, FR-WP-003 |

## Event subscribers

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/EventSubscriber/SecurityHeadersSubscriber.php` | Security headers | FR-HDR-001 … FR-HDR-005 |
| `src/EventSubscriber/AnonymousSessionCookieStripSubscriber.php` | Anonymous session strip | FR-STRIP-002 … FR-STRIP-006 |
| `src/EventSubscriber/ContentSecurityPolicySubscriber.php` | CSP (002/003) | FR-CSP-002 … FR-CSP-008 |
| `src/EventSubscriber/PublicCacheControlSubscriber.php` | Public cache (002) | FR-CACHE-002 … FR-CACHE-005 |
| `src/EventSubscriber/CanonicalHostRedirectSubscriber.php` | Canonical host (002) | FR-HOST-001 … FR-HOST-003 |
| `src/EventSubscriber/NoStoreSubscriber.php` | No-store paths (004) | FR-NS-002, FR-NS-003, FR-NS-004 |
| `src/EventSubscriber/TenantExistenceMaskSubscriber.php` | Tenant existence mask (006) | FR-TM-002 … FR-TM-005 |

## CSP and lint

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/Csp/CspSourceProviderInterface.php` | CSP (002) | FR-CSP-009 |
| `src/Csp/CspNonceStamper.php` | CSP (002/003) | FR-CSP-007, FR-CSP-010 |
| `src/Csp/InlineNonceLinter.php` | Lint command (002) | FR-LINT-002, FR-LINT-003 |
| `src/Command/LintCspNoncesCommand.php` | Lint command (002) | FR-LINT-001, FR-LINT-004 |

## HTTP helpers

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/Http/SafeInternalRedirect.php` | Safe URLs (002) | FR-SAFE-001, FR-SAFE-002 |
| `src/Http/SafeHref.php` | Safe URLs (002) | FR-SAFE-003 |
| `src/Http/BoundedRequestBody.php` | Bounded request body (005) | FR-BODY-001, FR-BODY-002, FR-BODY-003, FR-BODY-005 |
| `src/Http/Exception/RequestBodyTooLargeException.php` | Bounded request body (005) | FR-BODY-004 |
| `src/Http/JsonUpload.php` | Bounded JSON upload | FR-UPL-001 |
| `src/Http/Exception/JsonUploadException.php` | Bounded JSON upload | FR-UPL-002 |
| `src/Http/TrustedHostsEnvVarProcessor.php` | Trusted hosts env var processor | FR-TH-001 |

## Security SPI

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/Security/TenantAccessCheckerInterface.php` | Tenant existence mask (006) | FR-TM-005 |

## Twig

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/Twig/CspNonceExtension.php` | CSP (002) | FR-CSP-010 |
| `src/Twig/SafeUrlExtension.php` | Safe URLs (002) | FR-SAFE-004 |

## Additions 1.8–1.10 (rate limiting, route headers, framework-free ports)

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/Csp/CspSourceContributorInterface.php` | Ports (1.10) | FR-PORT-002 |
| `src/DependencyInjection/Compiler/LegacyPortDeprecationPass.php` | Ports (1.10) | FR-PORT-003 |
| `src/DependencyInjection/Compiler/RateLimitEventKeyPass.php` | Rate limiting (1.8) | FR-RL-003 |
| `src/DependencyInjection/Compiler/TenantAccessCheckerPass.php` | Ports (1.10) | FR-PORT-001, FR-TM-005 |
| `src/Event/RateLimitExceededEvent.php` | Rate limiting (1.8) | FR-RL-003 |
| `src/Event/TenantExistenceMaskedEvent.php` | Tenant existence mask (006) | FR-TM-009 |
| `src/EventSubscriber/RateLimitSubscriber.php` | Rate limiting (1.8) | FR-RL-002 |
| `src/EventSubscriber/RouteHeadersSubscriber.php` | Route headers (1.9) | FR-RH-001 |
| `src/Http/HttpRequestInfoFactory.php` | Ports (1.10) | FR-PORT-001 |
| `src/Http/RouterPath.php` | HTTP helpers | FR-RL-002, FR-RH-001 |
| `src/Http/SafeInternalRedirectInterface.php` | Safe URLs (002) | FR-SAFE-004 |
| `src/Model/HttpRequestInfo.php` | Ports (1.10) | FR-PORT-001 |
| `src/Model/RateLimitDecision.php` | Rate limiting (1.8) | FR-RL-001 |
| `src/Model/UserRef.php` | Ports (1.10) | FR-PORT-001 |
| `src/RateLimit/PerMinuteRateLimiter.php` | Rate limiting (1.8) | FR-RL-001 |
| `src/RateLimit/PerMinuteRateLimiterInterface.php` | Rate limiting (1.8) | FR-RL-001 |
| `src/RateLimit/RateLimitProviderInterface.php` | Rate limiting (1.8) | FR-RL-002, FR-PORT-003 |
| `src/RateLimit/RuleLimitProviderInterface.php` | Rate limiting (1.8) | FR-RL-002, FR-PORT-001 |
| `src/Security/TenantAccessQueryInterface.php` | Ports (1.10) | FR-PORT-001, FR-TM-005 |

## Coverage summary

| Category | Files | Mapped |
| -------- | ---: | ---: |
| Bundle / DI | 4 | 4 |
| Event subscribers | 7 | 7 |
| CSP and lint | 4 | 4 |
| HTTP helpers | 7 | 7 |
| Security SPI | 1 | 1 |
| Twig | 2 | 2 |
| Additions 1.8–1.10 | 19 | 19 |
| **Total production files (`git ls-files src`)** | **44** | **44** |

**Inventory complete — 44/44.**
