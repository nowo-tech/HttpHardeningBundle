# HTTP Hardening Bundle

[![CI](https://github.com/nowo-tech/HttpHardeningBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/HttpHardeningBundle/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/http-hardening-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/http-hardening-bundle)
[![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/http-hardening-bundle.svg)](https://packagist.org/packages/nowo-tech/http-hardening-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com)
[![GitHub stars](https://img.shields.io/github/stars/nowo-tech/http-hardening-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/HttpHardeningBundle)
[![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install it from [Packagist](https://packagist.org/packages/nowo-tech/http-hardening-bundle) and star [HttpHardeningBundle](https://github.com/nowo-tech/HttpHardeningBundle).

Symfony bundle for public HTTP hardening:

- **Security headers** — `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Resource-Policy`, optional HSTS
- **Anonymous session strip** — remove the session cookie on configured public GET/HEAD routes so CDNs and checkers see cacheable HTML
- **Content-Security-Policy** (opt-in) — per-request nonce in the `csp_nonce` request attribute, policy built from config, nonce stamping of inline `<script>`/`<style>` (skipped on `data-csp-strict-nonce` layouts), `CspSourceProviderInterface` for runtime origins, `csp_nonce()` / `|csp_stamp` Twig helpers
- **CSP nonce lint** — `nowo:http-hardening:lint-csp-nonces [dirs...]` fails on inline Twig blocks without a nonce (CI-friendly)
- **Public cache** (opt-in) — `private, max-age=120, must-revalidate` + `Vary: Cookie` on anonymous public pages, `public` policy on crawler/PWA files, no ETag on nonce'd HTML; coexists with the session strip
- **Canonical host** (opt-in) — single 301 from `www.` to the apex host of your public base URL
- **Safe URLs** — `SafeInternalRedirect` service (open-redirect guard) and `SafeHref`, with optional `safe_internal_path()` / `safe_href()` Twig functions

Compatible with Symfony 7.4, 8.0, and 8.1. PHP 8.2+ (Symfony 8.x requires PHP 8.4+).

## Requirements

- PHP ≥ 8.2
- Symfony 7.4 or 8.x (`http-kernel`, `security-core`)
- Optional: `twig/twig` (Twig helpers), `symfony/console` (lint command)

## Quick start

```bash
composer require nowo-tech/http-hardening-bundle
```

Security headers are on by default. Everything else (session strip, CSP, public cache, canonical host, safe-URL Twig functions) is opt-in — see [Configuration](docs/CONFIGURATION.md) and [Usage](docs/USAGE.md).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [PSR evaluation](docs/PSR.md)

## Tests and coverage

```bash
make test
make test-coverage
```

PHP statement coverage: **100%** (Clover gate ≥99% in CI).

## License

MIT — [Nowo.tech](https://nowo.tech)
