<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional\App;

use Nowo\HttpHardeningBundle\NowoHttpHardeningBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function is_string;

/**
 * Worker-mode app: security headers, CSP with a per-request nonce and a per-IP rate limit (400 per minute on `/hooks`),
 * driven by `WorkerKeptAliveKernelTest` through `http_kernel` without the services resetter.
 */
final class KeptAliveKernel extends Kernel
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
        return sys_get_temp_dir().'/nowo-http-hardening-kept-alive/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/nowo-http-hardening-kept-alive/log';
    }

    /**
     * Echoes the request's own data so the test can prove nothing is carried over between requests.
     */
    public static function echo(Request $request): Response
    {
        $nonce = $request->attributes->get('csp_nonce');

        return new Response('<html><body><p>'.$request->getClientIp().'|'.(is_string($nonce) ? $nonce : '').'</p></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
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
            'security_headers' => ['enabled' => true, 'hsts_enabled' => false],
            'csp' => ['enabled' => true],
            'rate_limit' => [
                'enabled' => true,
                'rules' => [
                    'hooks' => ['path_prefixes' => ['/hooks'], 'limit_per_minute' => 400],
                ],
            ],
        ]);

        $container->services()->set('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('hook', '/hooks/{name}')->controller([self::class, 'echo']);
    }
}
