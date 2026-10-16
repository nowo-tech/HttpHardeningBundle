<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional\App;

use Nowo\HttpHardeningBundle\NowoHttpHardeningBundle;
use Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface;
use Nowo\HttpHardeningBundle\Tests\Fixtures\RecordingTenantAccessChecker;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Minimal app: FrameworkBundle + SecurityBundle (HTTP basic, in-memory users) + this bundle with
 * `tenant_existence_mask` on `/projects/{tenant}/…`.
 *
 * Users: `member` has some access to tenant `aaaa` (but no admin role); `stranger` has access to nothing.
 */
final class TenantMaskKernel extends Kernel
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
        return sys_get_temp_dir().'/nowo-http-hardening-functional/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/nowo-http-hardening-functional/log';
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
            'tenant_existence_mask' => [
                'enabled' => true,
                'path_patterns' => ['#^/projects/(?<tenant>[0-9a-f]{4})(?:/|$)#i'],
            ],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(TenantMaskController::class)->public()->tag('controller.service_arguments');
        // The host's checker, found through the interface alias (the default `access_checker`).
        $services->set(TenantAccessCheckerInterface::class, RecordingTenantAccessChecker::class)
            ->args([['member' => ['aaaa']]]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('project_voter', '/projects/{tenant}/settings')->controller([TenantMaskController::class, 'voterDenied']);
        $routes->add('project_guard', '/projects/{tenant}/issues')->controller([TenantMaskController::class, 'guardDenied']);
        $routes->add('project_overview', '/projects/{tenant}')->controller([TenantMaskController::class, 'voterDenied']);
        $routes->add('project_unknown', '/projects/{tenant}/missing')->controller([TenantMaskController::class, 'notFound']);
        $routes->add('elsewhere', '/elsewhere/{tenant}')->controller([TenantMaskController::class, 'voterDenied']);
    }
}
