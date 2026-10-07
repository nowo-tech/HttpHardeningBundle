<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit;

use Nowo\HttpHardeningBundle\DependencyInjection\NowoHttpHardeningExtension;
use Nowo\HttpHardeningBundle\NowoHttpHardeningBundle;
use PHPUnit\Framework\TestCase;

final class NowoHttpHardeningBundleTest extends TestCase
{
    public function testGetContainerExtensionReturnsConfiguredExtension(): void
    {
        $bundle = new NowoHttpHardeningBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(NowoHttpHardeningExtension::class, $extension);
        self::assertSame($extension, $bundle->getContainerExtension());
    }
}
