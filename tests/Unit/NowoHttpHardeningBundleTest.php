<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit;

use Nowo\HttpHardeningBundle\DependencyInjection\Compiler\WebProfilerCspHandlerPass;
use Nowo\HttpHardeningBundle\DependencyInjection\NowoHttpHardeningExtension;
use Nowo\HttpHardeningBundle\NowoHttpHardeningBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoHttpHardeningBundleTest extends TestCase
{
    public function testGetContainerExtensionReturnsConfiguredExtension(): void
    {
        $bundle = new NowoHttpHardeningBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(NowoHttpHardeningExtension::class, $extension);
        self::assertSame($extension, $bundle->getContainerExtension());
    }

    public function testBuildRegistersWebProfilerCspHandlerPass(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningBundle())->build($container);

        $passes = array_filter(
            $container->getCompilerPassConfig()->getBeforeOptimizationPasses(),
            static fn (object $pass): bool => $pass instanceof WebProfilerCspHandlerPass,
        );
        self::assertCount(1, $passes);
    }
}
