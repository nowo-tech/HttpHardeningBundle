# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/http-hardening-bundle` (`symfony-bundle`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.
Only contracts that add clear interoperability or maintainability value are **Adopted**.
Others are **N/A**.

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` `autoload` / `autoload-dev` PSR-4 maps. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **N/A** | Subscribers do not log. No `psr/log` dependency. |
| PSR-6 / PSR-16 Cache | **N/A** | No cache layer. |
| PSR-7 / PSR-17 HTTP messages | **N/A** | Uses Symfony HttpFoundation; does not expose PSR-7 APIs. |
| PSR-11 Container | **N/A** | Constructor injection only. |
| PSR-14 Event dispatcher | **N/A** | Uses Symfony kernel events via attributes/tags; no PSR-14. |
| PSR-15 HTTP middleware | **N/A** | Kernel response listeners, not middleware. |
| PSR-18 HTTP client | **N/A** | No outbound HTTP. |
| PSR-20 Clock | **N/A** | No time-dependent logic. |

## Summary

- **Adopted beyond baseline:** none (baseline PSR-12 + PSR-4 only).
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.

_REQ-CS-007 evaluation date: 2026-10-07._
