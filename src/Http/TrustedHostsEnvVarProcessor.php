<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Http;

use Closure;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

use function implode;
use function in_array;
use function is_string;
use function parse_url;
use function preg_quote;
use function strtolower;
use function trim;

use const PHP_URL_HOST;

/**
 * `%env(nowo_trusted_hosts:SYMFONY_TRUSTED_HOSTS)%` for `framework.trusted_hosts`: production never runs
 * without a Host allowlist. First non-empty value wins:
 *
 * 1. the named variable (e.g. `SYMFONY_TRUSTED_HOSTS`),
 * 2. the fallback variable (`trusted_hosts.fallback_env`, default `TRUSTED_HOSTS` — what devops templates write),
 * 3. outside `trusted_hosts.local_environments`: derived from the host of `trusted_hosts.public_url_env`
 *    (default `DEFAULT_URI`) plus `trusted_hosts.internal_hosts` (container alias, loopback for probes):
 *    `^(?:app\.example\.com|app-php|localhost|127\.0\.0\.1)$`.
 *
 * In local environments with nothing set the list stays empty (any Host, Symfony's default).
 */
final readonly class TrustedHostsEnvVarProcessor implements EnvVarProcessorInterface
{
    public const PREFIX = 'nowo_trusted_hosts';

    /**
     * @param list<string> $internalHosts     Plain host names (quoted into the regex)
     * @param list<string> $localEnvironments kernel.environment values that keep an empty list
     */
    public function __construct(
        private string $environment,
        private string $fallbackEnv = 'TRUSTED_HOSTS',
        private string $publicUrlEnv = 'DEFAULT_URI',
        private array $internalHosts = ['localhost', '127.0.0.1'],
        private array $localEnvironments = ['dev', 'test'],
    ) {
    }

    public function getEnv(string $prefix, string $name, Closure $getEnv): mixed
    {
        foreach ([$name, $this->fallbackEnv] as $variable) {
            $value = $this->read($getEnv, $variable);
            if ('' !== $value) {
                return $value;
            }
        }

        if (in_array($this->environment, $this->localEnvironments, true)) {
            return '';
        }

        $host = parse_url($this->read($getEnv, $this->publicUrlEnv), PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return '';
        }

        $hosts = [preg_quote(strtolower($host))];
        foreach ($this->internalHosts as $internal) {
            $hosts[] = preg_quote(strtolower($internal));
        }

        return '^(?:'.implode('|', $hosts).')$';
    }

    public static function getProvidedTypes(): array
    {
        return [self::PREFIX => 'string'];
    }

    private function read(Closure $getEnv, string $variable): string
    {
        if ('' === $variable) {
            return '';
        }

        try {
            return trim((string) $getEnv($variable));
        } catch (EnvNotFoundException) {
            return '';
        }
    }
}
