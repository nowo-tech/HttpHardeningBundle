<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional\App;

use Nowo\HttpHardeningBundle\NowoHttpHardeningBundle;
use Nowo\HttpHardeningBundle\Tests\Fixtures\FixedRateLimitProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal app: FrameworkBundle (filesystem cache.app, cleared by each test) + this bundle with `rate_limit` on `/hooks` (2 per minute)
 * and `/api/{project}/ingest` (limit from a runtime provider: 1 per minute).
 */
final class RateLimitKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new NowoHttpHardeningBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/nowo-http-hardening-rate-limit/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/nowo-http-hardening-rate-limit/log';
    }

    public static function ok(): Response
    {
        return new Response('ok');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'router' => ['utf8' => true],
        ]);

        $container->extension('nowo_http_hardening', [
            'security_headers' => ['enabled' => false],
            'rate_limit' => [
                'enabled' => true,
                'rules' => [
                    'hooks' => ['path_prefixes' => ['/hooks'], 'limit_per_minute' => 2],
                    'ingest' => [
                        'path_patterns' => ['#^/api/[^/]+/ingest(?:/|$)#'],
                        'methods' => ['post'],
                        'limit_provider' => FixedRateLimitProvider::class,
                    ],
                ],
            ],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(FixedRateLimitProvider::class)->args([1]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('hook', '/hooks/{name}')->controller([self::class, 'ok']);
        $routes->add('ingest', '/api/{project}/ingest')->controller([self::class, 'ok']);
        $routes->add('home', '/')->controller([self::class, 'ok']);
    }
}
