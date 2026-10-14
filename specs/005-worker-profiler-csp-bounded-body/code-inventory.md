# Code inventory — 005 worker-safe profiler CSP handler and bounded request body

**Last audited:** 2026-10-14  
**Aligned with:** **v1.5.0**

| Path | Spec section | Requirement ID(s) |
| ---- | ------------ | ----------------- |
| `src/NowoHttpHardeningBundle.php` (extended) | Profiler CSP handler | FR-WP-002 |
| `src/DependencyInjection/Configuration.php` (extended) | Profiler CSP handler | FR-WP-001 |
| `src/DependencyInjection/NowoHttpHardeningExtension.php` (extended) | Profiler CSP handler | FR-WP-001 |
| `src/DependencyInjection/Compiler/WebProfilerCspHandlerPass.php` (new) | Profiler CSP handler | FR-WP-002, FR-WP-003 |
| `src/Http/BoundedRequestBody.php` (new) | Bounded request body | FR-BODY-001, FR-BODY-002, FR-BODY-003, FR-BODY-005 |
| `src/Http/Exception/RequestBodyTooLargeException.php` (new) | Bounded request body | FR-BODY-004 |
