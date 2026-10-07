# Installation

```bash
composer require nowo-tech/http-hardening-bundle
```

With Symfony Flex the recipe registers the bundle and copies `config/packages/nowo_http_hardening.yaml`.

Without Flex, enable `Nowo\HttpHardeningBundle\NowoHttpHardeningBundle` in `config/bundles.php` and copy the recipe YAML from `.symfony/recipe/`.

Next steps:

- [Configuration](CONFIGURATION.md) — header values and optional anonymous session strip
- [Security](SECURITY.md) — threat model and host duties (CSP, public-route lists)
- [Upgrading](UPGRADING.md) — version notes
