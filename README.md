# HTTP Hardening Bundle

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

See [docs/CONFIGURATION.md](docs/CONFIGURATION.md) and [docs/UPGRADING.md](docs/UPGRADING.md).

## License

MIT — [Nowo.tech](https://nowo.tech)
