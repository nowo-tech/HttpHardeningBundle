# HTTP Hardening Bundle

[![CI](https://github.com/nowo-tech/HttpHardeningBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/HttpHardeningBundle/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/http-hardening-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/http-hardening-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.x-000000?logo=symfony)](https://symfony.com)
[![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install it from [Packagist](https://packagist.org/packages/nowo-tech/http-hardening-bundle) and star [HttpHardeningBundle](https://github.com/nowo-tech/HttpHardeningBundle).

Symfony bundle for public HTTP hardening:

- **Security headers** — `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Resource-Policy`, optional HSTS
- **Anonymous session strip** — remove the session cookie on configured public GET/HEAD routes so CDNs and checkers see cacheable HTML

## Requirements

- PHP ≥ 8.2
- Symfony 7.4 or 8.x (`http-kernel`, `security-core`)

## Quick start

```bash
composer require nowo-tech/http-hardening-bundle
```

Security headers are on by default. Anonymous session strip stays off until you list public routes — see [Configuration](docs/CONFIGURATION.md).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Security](docs/SECURITY.md)
- [Upgrading](docs/UPGRADING.md)
- [Changelog](docs/CHANGELOG.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Release](docs/RELEASE.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)

## Tests and coverage

```bash
make test
make test-coverage
```

PHP statement coverage: **100%** (Clover gate ≥99% in CI).

## License

MIT — [Nowo.tech](https://nowo.tech)
