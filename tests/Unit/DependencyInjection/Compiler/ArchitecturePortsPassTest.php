<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\HttpHardeningBundle\DependencyInjection\Compiler\LegacyPortDeprecationPass;
use Nowo\HttpHardeningBundle\DependencyInjection\Compiler\TenantAccessCheckerPass;
use Nowo\HttpHardeningBundle\DependencyInjection\NowoHttpHardeningExtension;
use Nowo\HttpHardeningBundle\EventSubscriber\RateLimitSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\TenantExistenceMaskSubscriber;
use Nowo\HttpHardeningBundle\Http\SafeInternalRedirect;
use Nowo\HttpHardeningBundle\Http\SafeInternalRedirectInterface;
use Nowo\HttpHardeningBundle\NowoHttpHardeningBundle;
use Nowo\HttpHardeningBundle\RateLimit\PerMinuteRateLimiter;
use Nowo\HttpHardeningBundle\RateLimit\PerMinuteRateLimiterInterface;
use Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface;
use Nowo\HttpHardeningBundle\Security\TenantAccessQueryInterface;
use Nowo\HttpHardeningBundle\Tests\Fixtures\BothCspProvider;
use Nowo\HttpHardeningBundle\Tests\Fixtures\BothPortsTenantAccessChecker;
use Nowo\HttpHardeningBundle\Tests\Fixtures\FixedRateLimitProvider;
use Nowo\HttpHardeningBundle\Tests\Fixtures\FixedRuleLimitProvider;
use Nowo\HttpHardeningBundle\Tests\Fixtures\LegacyCspProvider;
use Nowo\HttpHardeningBundle\Tests\Fixtures\LegacyMapTenantAccessChecker;
use Nowo\HttpHardeningBundle\Tests\Fixtures\MapTenantAccessQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;

use const E_USER_DEPRECATED;

final class ArchitecturePortsPassTest extends TestCase
{
    public function testTheNewTenantPortWinsWhenRegistered(): void
    {
        $container = $this->container(['tenant_existence_mask' => ['enabled' => true, 'path_patterns' => ['#^/t/([^/]+)#']]]);
        $container->register(TenantAccessCheckerInterface::class, LegacyMapTenantAccessChecker::class);
        $container->register(TenantAccessQueryInterface::class, MapTenantAccessQuery::class);

        (new TenantAccessCheckerPass())->process($container);

        self::assertSame(TenantAccessQueryInterface::class, (string) $this->checkerArgument($container));
    }

    public function testTheDeprecatedTenantPortStaysWithoutTheNewOne(): void
    {
        $container = $this->container(['tenant_existence_mask' => ['enabled' => true, 'path_patterns' => ['#^/t/([^/]+)#']]]);
        $container->register(TenantAccessCheckerInterface::class, LegacyMapTenantAccessChecker::class);

        (new TenantAccessCheckerPass())->process($container);

        self::assertSame(TenantAccessCheckerInterface::class, (string) $this->checkerArgument($container));
    }

    public function testAnExplicitCheckerIdIsLeftAlone(): void
    {
        $container = $this->container(['tenant_existence_mask' => ['enabled' => true, 'path_patterns' => ['#^/t/([^/]+)#'], 'access_checker' => 'app.checker']]);
        $container->register(TenantAccessQueryInterface::class, MapTenantAccessQuery::class);

        (new TenantAccessCheckerPass())->process($container);
        (new TenantAccessCheckerPass())->process(new ContainerBuilder());

        self::assertSame('app.checker', (string) $this->checkerArgument($container));
    }

    /**
     * Deny by default: no host port at all still fails the container build (the mask never guesses).
     */
    public function testNoTenantPortFailsTheBuild(): void
    {
        $container = $this->compilable(['tenant_existence_mask' => ['enabled' => true, 'path_patterns' => ['#^/t/([^/]+)#']]]);
        $container->getDefinition(TenantExistenceMaskSubscriber::class)->setPublic(true);

        $this->expectException(ServiceNotFoundException::class);
        $container->compile();
    }

    public function testCompiledContainerUsesTheNewPortAndAliases(): void
    {
        $container = $this->compilable([
            'tenant_existence_mask' => ['enabled' => true, 'path_patterns' => ['#^/t/([^/]+)#']],
            'rate_limit' => ['enabled' => true, 'cache_pool' => 'test.cache', 'rules' => ['r' => ['path_prefixes' => ['/r'], 'limit_provider' => FixedRuleLimitProvider::class]]],
        ]);
        $container->register(TenantAccessQueryInterface::class, BothPortsTenantAccessChecker::class)->setArgument('$queryAnswer', true);
        $container->register(FixedRuleLimitProvider::class)->setArgument('$limit', 1);
        $container->getDefinition(TenantExistenceMaskSubscriber::class)->setPublic(true);
        $container->getDefinition(RateLimitSubscriber::class)->setPublic(true);
        $container->getAlias(SafeInternalRedirectInterface::class)->setPublic(true);
        $container->getAlias(PerMinuteRateLimiterInterface::class)->setPublic(true);

        $container->compile();

        self::assertInstanceOf(TenantExistenceMaskSubscriber::class, $container->get(TenantExistenceMaskSubscriber::class));
        self::assertInstanceOf(RateLimitSubscriber::class, $container->get(RateLimitSubscriber::class));
        self::assertInstanceOf(SafeInternalRedirect::class, $container->get(SafeInternalRedirectInterface::class));
        self::assertInstanceOf(PerMinuteRateLimiter::class, $container->get(PerMinuteRateLimiterInterface::class));
    }

    public function testLegacyPortsAreReportedOnce(): void
    {
        $container = $this->container([
            'tenant_existence_mask' => ['enabled' => true, 'path_patterns' => ['#^/t/([^/]+)#']],
            'rate_limit' => ['enabled' => true, 'rules' => [
                'old' => ['path_prefixes' => ['/a'], 'limit_provider' => FixedRateLimitProvider::class],
                'new' => ['path_prefixes' => ['/b'], 'limit_provider' => FixedRuleLimitProvider::class],
                'gone' => ['path_prefixes' => ['/c'], 'limit_provider' => 'missing.provider'],
            ]],
            'csp' => ['enabled' => true],
        ]);
        $container->register(TenantAccessCheckerInterface::class, LegacyMapTenantAccessChecker::class);
        $container->register(FixedRateLimitProvider::class);
        $container->register(FixedRuleLimitProvider::class);
        $container->setParameter('csp.class', LegacyCspProvider::class);
        $container->register('csp.old', '%csp.class%')->addTag(CspSourceProviderInterface::TAG);
        $container->register('csp.both', BothCspProvider::class)->addTag(CspSourceProviderInterface::TAG);
        $container->register('csp.factory')->addTag(CspSourceProviderInterface::TAG);
        $container->register('csp.unknown', 'App\\Missing\\Provider')->addTag(CspSourceProviderInterface::TAG);

        $messages = $this->deprecations(static fn () => (new LegacyPortDeprecationPass())->process($container));

        self::assertCount(3, $messages);
        self::assertStringContainsString(TenantAccessQueryInterface::class, $messages[0]);
        self::assertStringContainsString(FixedRateLimitProvider::class, $messages[1]);
        self::assertStringContainsString('"csp.old"', $messages[2]);
        self::assertStringContainsString('removed in 2.0', $messages[2]);
    }

    public function testNewPortsAreNotReported(): void
    {
        $container = $this->container(['tenant_existence_mask' => ['enabled' => true, 'path_patterns' => ['#^/t/([^/]+)#']]]);
        $container->register(TenantAccessQueryInterface::class, BothPortsTenantAccessChecker::class);
        (new TenantAccessCheckerPass())->process($container);

        self::assertSame([], $this->deprecations(static fn () => (new LegacyPortDeprecationPass())->process($container)));
        self::assertSame([], $this->deprecations(static fn () => (new LegacyPortDeprecationPass())->process(new ContainerBuilder())));
    }

    public function testBundleRegistersThePasses(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningBundle())->build($container);
        $classes = array_map(static fn (object $pass): string => $pass::class, $container->getCompilerPassConfig()->getBeforeOptimizationPasses());

        self::assertContains(TenantAccessCheckerPass::class, $classes);
        self::assertContains(LegacyPortDeprecationPass::class, $classes);
    }

    /**
     * @return list<string>
     */
    private function deprecations(callable $run): array
    {
        $messages = [];
        // @phpstan-ignore-next-line test-only, restored in the finally below
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $run();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([$config], $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function compilable(array $config): ContainerBuilder
    {
        $container = $this->container($config);
        $container->register('test.cache', ArrayAdapter::class);
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.debug', false);
        (new NowoHttpHardeningBundle())->build($container);

        return $container;
    }

    private function checkerArgument(ContainerBuilder $container): Reference
    {
        $argument = $container->getDefinition(TenantExistenceMaskSubscriber::class)->getArgument('$accessChecker');
        self::assertInstanceOf(Reference::class, $argument);

        return $argument;
    }
}
