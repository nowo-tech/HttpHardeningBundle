<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpHardeningBundle\DependencyInjection\Configuration;
use Nowo\HttpHardeningBundle\DependencyInjection\NowoHttpHardeningExtension;
use Nowo\HttpHardeningBundle\EventSubscriber\TenantExistenceMaskSubscriber;
use Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class TenantExistenceMaskConfigurationTest extends TestCase
{
    private const PATTERN = '#^/projects/(?<tenant>[0-9a-f-]{36})(?:/|$)#i';

    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame([
            'enabled' => false,
            'path_patterns' => [],
            'access_checker' => null,
            'priority' => 16,
        ], $config['tenant_existence_mask']);
    }

    public function testAcceptsPatternsWithACaptureGroup(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'tenant_existence_mask' => [
                'enabled' => true,
                'path_patterns' => [self::PATTERN, '#^/orgs/([^/]+)#'],
                'access_checker' => 'app.checker',
                'priority' => 32,
            ],
        ]]);

        self::assertSame([self::PATTERN, '#^/orgs/([^/]+)#'], $config['tenant_existence_mask']['path_patterns']);
        self::assertSame('app.checker', $config['tenant_existence_mask']['access_checker']);
        self::assertSame(32, $config['tenant_existence_mask']['priority']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPatterns(): iterable
    {
        yield 'no delimiters' => ['^/projects/([^/]+)'];
        yield 'unbalanced group' => ['#^/projects/([^/]+#'];
        yield 'empty' => [''];
        yield 'no capture group' => ['#^/projects/[^/]+#'];
        yield 'only non-capturing group' => ['#^/projects/(?:[^/]+)#'];
        yield 'only a lookahead' => ['#^/projects/(?=x)#'];
    }

    #[DataProvider('invalidPatterns')]
    public function testRejectsInvalidPatterns(mixed $pattern): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('tenant_existence_mask.path_patterns');

        (new Processor())->processConfiguration(new Configuration(), [[
            'tenant_existence_mask' => ['enabled' => true, 'path_patterns' => [$pattern]],
        ]]);
    }

    public function testEnabledRequiresAPattern(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires at least one path_patterns entry');

        (new Processor())->processConfiguration(new Configuration(), [['tenant_existence_mask' => ['enabled' => true]]]);
    }

    public function testPriorityMustStayAboveTheFirewallExceptionListener(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'tenant_existence_mask' => ['enabled' => true, 'path_patterns' => [self::PATTERN], 'priority' => 1],
        ]]);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function captureGroups(): iterable
    {
        yield 'plain' => ['#^/p/([^/]+)#', true];
        yield 'named' => ['#^/p/(?<tenant>[^/]+)#', true];
        yield 'python named' => ['#^/p/(?P<tenant>[^/]+)#', true];
        yield 'quoted named' => ["#^/p/(?'tenant'[^/]+)#", true];
        yield 'non-capturing' => ['#^/p/(?:[^/]+)#', false];
        yield 'lookbehind' => ['#^/p/(?<=x)y#', false];
        yield 'negative lookbehind' => ['#^/p/(?<!x)y#', false];
        yield 'escaped parenthesis' => ['#^/p/\(x\)#', false];
    }

    #[DataProvider('captureGroups')]
    public function testHasCaptureGroup(string $pattern, bool $expected): void
    {
        self::assertSame($expected, Configuration::hasCaptureGroup($pattern));
    }

    public function testOffByDefault(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([['tenant_existence_mask' => ['path_patterns' => [self::PATTERN]]]], $container);

        self::assertFalse($container->hasDefinition(TenantExistenceMaskSubscriber::class));
    }

    public function testWiringWithTheDefaultChecker(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([[
            'tenant_existence_mask' => ['enabled' => true, 'path_patterns' => [self::PATTERN]],
        ]], $container);

        $definition = $container->getDefinition(TenantExistenceMaskSubscriber::class);
        self::assertSame([self::PATTERN], $definition->getArgument('$pathPatterns'));
        $checker = $definition->getArgument('$accessChecker');
        self::assertInstanceOf(Reference::class, $checker);
        self::assertSame(TenantAccessCheckerInterface::class, (string) $checker);
        $tokenStorage = $definition->getArgument('$tokenStorage');
        self::assertInstanceOf(Reference::class, $tokenStorage);
        self::assertSame('security.token_storage', (string) $tokenStorage);
        self::assertSame(ContainerBuilder::NULL_ON_INVALID_REFERENCE, $tokenStorage->getInvalidBehavior());
        self::assertSame([['event' => 'kernel.exception', 'method' => 'onException', 'priority' => 16]], $definition->getTag('kernel.event_listener'));
    }

    public function testWiringWithACustomCheckerAndPriority(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([[
            'tenant_existence_mask' => ['enabled' => true, 'path_patterns' => [self::PATTERN], 'access_checker' => 'app.tenant_checker', 'priority' => 64],
        ]], $container);

        $definition = $container->getDefinition(TenantExistenceMaskSubscriber::class);
        self::assertSame('app.tenant_checker', (string) $definition->getArgument('$accessChecker'));
        self::assertSame([['event' => 'kernel.exception', 'method' => 'onException', 'priority' => 64]], $definition->getTag('kernel.event_listener'));
    }
}
