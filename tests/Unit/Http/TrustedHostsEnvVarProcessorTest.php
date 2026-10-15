<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Http;

use Closure;
use Nowo\HttpHardeningBundle\Http\TrustedHostsEnvVarProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

final class TrustedHostsEnvVarProcessorTest extends TestCase
{
    public function testNamedVariableWins(): void
    {
        self::assertSame('^a\.example$', $this->resolve('prod', ['SYMFONY_TRUSTED_HOSTS' => ' ^a\.example$ ', 'TRUSTED_HOSTS' => 'x']));
    }

    public function testFallsBackToTheDevopsVariable(): void
    {
        self::assertSame('^b\.example$', $this->resolve('prod', ['SYMFONY_TRUSTED_HOSTS' => '', 'TRUSTED_HOSTS' => '^b\.example$']));
    }

    public function testDerivesFromThePublicUrlOutsideLocalEnvironments(): void
    {
        $processor = new TrustedHostsEnvVarProcessor('prod', internalHosts: ['app-php', 'localhost', '127.0.0.1']);

        self::assertSame(
            '^(?:beacon\.example\.com|app\-php|localhost|127\.0\.0\.1)$',
            $processor->getEnv('nowo_trusted_hosts', 'SYMFONY_TRUSTED_HOSTS', $this->env(['DEFAULT_URI' => 'https://Beacon.Example.com/path'])),
        );
    }

    public function testLocalEnvironmentsAndMissingPublicUrlKeepAnEmptyList(): void
    {
        self::assertSame('', $this->resolve('dev', ['DEFAULT_URI' => 'https://app.example.com']));
        self::assertSame('', $this->resolve('prod', []));
        self::assertSame('', $this->resolve('prod', ['DEFAULT_URI' => 'not a url']));
    }

    public function testEmptyFallbackNameIsSkipped(): void
    {
        $processor = new TrustedHostsEnvVarProcessor('prod', fallbackEnv: '', publicUrlEnv: 'APP_URL', internalHosts: []);

        self::assertSame('^(?:app\.example\.com)$', $processor->getEnv('nowo_trusted_hosts', 'SYMFONY_TRUSTED_HOSTS', $this->env(['APP_URL' => 'https://app.example.com'])));
        self::assertSame(['nowo_trusted_hosts' => 'string'], TrustedHostsEnvVarProcessor::getProvidedTypes());
    }

    /**
     * @param array<string, string> $vars
     */
    private function resolve(string $environment, array $vars): string
    {
        $value = (new TrustedHostsEnvVarProcessor($environment))->getEnv('nowo_trusted_hosts', 'SYMFONY_TRUSTED_HOSTS', $this->env($vars));
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, string> $vars
     */
    private function env(array $vars): Closure
    {
        return static function (string $name) use ($vars): string {
            if (!isset($vars[$name])) {
                throw new EnvNotFoundException($name);
            }

            return $vars[$name];
        };
    }
}
