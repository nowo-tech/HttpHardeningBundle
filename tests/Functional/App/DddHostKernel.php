<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional\App;

use Nowo\HttpHardeningBundle\Event\RateLimitExceededEvent;
use Nowo\HttpHardeningBundle\Event\TenantExistenceMaskedEvent;
use Nowo\HttpHardeningBundle\NowoHttpHardeningBundle;
use Nowo\HttpHardeningBundle\Security\TenantAccessQueryInterface;
use Nowo\HttpHardeningBundle\Tests\Functional\App\DddHost\CdnCspSources;
use Nowo\HttpHardeningBundle\Tests\Functional\App\DddHost\IngestLimitFromSettings;
use Nowo\HttpHardeningBundle\Tests\Functional\App\DddHost\SecurityEventLog;
use Nowo\HttpHardeningBundle\Tests\Functional\App\DddHost\TenantMembershipAccess;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * DDD / hexagonal host: only the framework-free ports (`TenantAccessQueryInterface`, `RuleLimitProviderInterface`,
 * `CspSourceContributorInterface`) are implemented, in plain-PHP adapters under `DddHost/` (no Symfony import), and
 * the scalar security events are consumed. Nothing implements a deprecated port.
 *
 * Same users and tenants as {@see TenantMaskKernel}: `member` has some access to `aaaa`; `stranger` to nothing.
 */
final class DddHostKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new NowoHttpHardeningBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/nowo-http-hardening-ddd-host/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/nowo-http-hardening-ddd-host/log';
    }

    public static function ok(): Response
    {
        return new Response('ok');
    }

    public static function page(): Response
    {
        return new Response('<html><body><p>page</p></body></html>', 200, ['Content-Type' => 'text/html']);
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

        $container->extension('security', [
            'password_hashers' => [InMemoryUser::class => 'plaintext'],
            'providers' => [
                'users' => ['memory' => ['users' => [
                    'member' => ['password' => 'pw', 'roles' => ['ROLE_USER']],
                    'stranger' => ['password' => 'pw', 'roles' => ['ROLE_USER']],
                ]]],
            ],
            'firewalls' => [
                'main' => ['stateless' => true, 'http_basic' => null, 'provider' => 'users'],
            ],
        ]);

        $container->extension('nowo_http_hardening', [
            'security_headers' => ['enabled' => false],
            'csp' => ['enabled' => true],
            'tenant_existence_mask' => [
                'enabled' => true,
                'path_patterns' => ['#^/projects/(?<tenant>[0-9a-f]{4})(?:/|$)#i'],
            ],
            'rate_limit' => [
                'enabled' => true,
                'rules' => [
                    'ingest' => [
                        'path_patterns' => ['#^/api/[^/]+/ingest(?:/|$)#'],
                        'limit_provider' => IngestLimitFromSettings::class,
                    ],
                ],
            ],
        ]);

        $services = $container->services();
        $services->defaults()->autoconfigure();
        $services->set('logger', NullLogger::class);
        $services->set(TenantMaskController::class)->public()->tag('controller.service_arguments');
        // Only the framework-free port: found through its interface alias (the default `access_checker`).
        $services->set(TenantAccessQueryInterface::class, TenantMembershipAccess::class)
            ->args([['member' => ['aaaa']]]);
        $services->set(IngestLimitFromSettings::class)->args([['small' => 1, 'free' => 0]]);
        $services->set(CdnCspSources::class);
        $services->set(SecurityEventLog::class)->public()
            ->tag('kernel.event_listener', ['event' => TenantExistenceMaskedEvent::class, 'method' => 'onMasked'])
            ->tag('kernel.event_listener', ['event' => RateLimitExceededEvent::class, 'method' => 'onRateLimited']);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('project_voter', '/projects/{tenant}/settings')->controller([TenantMaskController::class, 'voterDenied']);
        $routes->add('project_guard', '/projects/{tenant}/issues')->controller([TenantMaskController::class, 'guardDenied']);
        $routes->add('project_overview', '/projects/{tenant}')->controller([TenantMaskController::class, 'voterDenied']);
        $routes->add('project_unknown', '/projects/{tenant}/missing')->controller([TenantMaskController::class, 'notFound']);
        $routes->add('elsewhere', '/elsewhere/{tenant}')->controller([TenantMaskController::class, 'voterDenied']);
        $routes->add('ingest', '/api/{project}/ingest')->controller([self::class, 'ok']);
        $routes->add('page', '/page')->controller([self::class, 'page']);
    }
}
