# Code inventory — 006 tenant existence mask

**Last audited:** 2026-10-16  
**Aligned with:** **v1.7.0**

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/DependencyInjection/Configuration.php` (extended) | Config | FR-TM-001, FR-TM-006, FR-TM-007 |
| `src/DependencyInjection/NowoHttpHardeningExtension.php` (extended) | Config | FR-TM-001, FR-TM-006, FR-TM-008 |
| `src/EventSubscriber/TenantExistenceMaskSubscriber.php` (new) | Mask | FR-TM-002, FR-TM-003, FR-TM-004, FR-TM-005 |
| `src/Security/TenantAccessCheckerInterface.php` (new) | SPI | FR-TM-005 |
| `tests/Functional/App/*` (new) | Acceptance | FR-TM-004, FR-TM-005, FR-TM-006 |
