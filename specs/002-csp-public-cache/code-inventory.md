# Code inventory — 002 CSP / public cache / canonical host / safe URLs

**Last audited:** 2026-10-09  
**Aligned with:** unreleased (target **v1.1.0**)

Production files added or extended by this feature. Baseline files are listed in [001-baseline/code-inventory.md](../001-baseline/code-inventory.md).

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/DependencyInjection/Configuration.php` (extended) | All | FR-CSP-001, FR-CACHE-001, FR-HOST-001, FR-HOST-003, FR-SAFE-004 |
| `src/DependencyInjection/NowoHttpHardeningExtension.php` (extended) | All | FR-CSP-001, FR-CSP-009, FR-LINT-004, FR-CACHE-001, FR-HOST-001, FR-SAFE-002, FR-SAFE-004 |
| `src/EventSubscriber/ContentSecurityPolicySubscriber.php` | CSP | FR-CSP-002 … FR-CSP-008 |
| `src/Csp/CspSourceProviderInterface.php` | CSP | FR-CSP-009 |
| `src/Csp/CspNonceStamper.php` | CSP | FR-CSP-007, FR-CSP-010 |
| `src/Twig/CspNonceExtension.php` | CSP | FR-CSP-010 |
| `src/Csp/InlineNonceLinter.php` | Lint command | FR-LINT-002, FR-LINT-003 |
| `src/Command/LintCspNoncesCommand.php` | Lint command | FR-LINT-001, FR-LINT-004 |
| `src/EventSubscriber/PublicCacheControlSubscriber.php` | Public cache | FR-CACHE-002 … FR-CACHE-005 |
| `src/EventSubscriber/CanonicalHostRedirectSubscriber.php` | Canonical host | FR-HOST-001 … FR-HOST-003 |
| `src/Http/SafeInternalRedirect.php` | Safe URLs | FR-SAFE-001, FR-SAFE-002 |
| `src/Http/SafeHref.php` | Safe URLs | FR-SAFE-003 |
| `src/Twig/SafeUrlExtension.php` | Safe URLs | FR-SAFE-004 |

## Coverage summary

| Category | New files | Mapped |
| -------- | --------- | ------ |
| PHP under `src/` | **11** | **11** |

Together with the baseline: **16/16** production files mapped.
