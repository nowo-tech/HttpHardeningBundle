# Code inventory — baseline (100% of production `src/`)

**Last audited:** 2026-10-07  
**Aligned with:** **v1.0.3**

Every production PHP unit under `src/` is listed. Tests and tooling are out of scope.

## Bundle / DI

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/NowoHttpHardeningBundle.php` | Bundle / DI | FR-BUNDLE-001 |
| `src/DependencyInjection/Configuration.php` | Bundle / DI | FR-DI-001, FR-STRIP-001 |
| `src/DependencyInjection/NowoHttpHardeningExtension.php` | Bundle / DI | FR-DI-002, FR-DI-003, FR-DI-004 |

## Event subscribers

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/EventSubscriber/SecurityHeadersSubscriber.php` | Security headers | FR-HDR-001 … FR-HDR-005 |
| `src/EventSubscriber/AnonymousSessionCookieStripSubscriber.php` | Anonymous session strip | FR-STRIP-002 … FR-STRIP-006 |

## Coverage summary

| Category | Mapped | Production files |
| -------- | ------ | ---------------- |
| PHP under `src/` | **5** | **5** |

**Inventory complete — 5/5.** No Twig, YAML resources, or frontend assets under `src/`.
