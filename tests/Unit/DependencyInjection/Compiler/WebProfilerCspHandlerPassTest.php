<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\HttpHardeningBundle\DependencyInjection\Compiler\WebProfilerCspHandlerPass;
use Nowo\HttpHardeningBundle\DependencyInjection\NowoHttpHardeningExtension;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class WebProfilerCspHandlerPassTest extends TestCase
{
    public function testMakesProfilerHandlerNonSharedByDefault(): void
    {
        $container = $this->containerWithHandler([]);

        (new WebProfilerCspHandlerPass())->process($container);

        self::assertFalse($container->getDefinition(WebProfilerCspHandlerPass::SERVICE_ID)->isShared());
    }

    public function testWithoutParameterStillIsolates(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(WebProfilerCspHandlerPass::SERVICE_ID, new Definition(stdClass::class));

        (new WebProfilerCspHandlerPass())->process($container);

        self::assertFalse($container->getDefinition(WebProfilerCspHandlerPass::SERVICE_ID)->isShared());
    }

    public function testSwitchOffLeavesHandlerShared(): void
    {
        $container = $this->containerWithHandler(['web_profiler' => ['isolate_csp_handler' => false]]);

        (new WebProfilerCspHandlerPass())->process($container);

        self::assertTrue($container->getDefinition(WebProfilerCspHandlerPass::SERVICE_ID)->isShared());
    }

    public function testNoOpWithoutWebProfilerBundle(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([], $container);

        (new WebProfilerCspHandlerPass())->process($container);

        self::assertFalse($container->hasDefinition(WebProfilerCspHandlerPass::SERVICE_ID));
    }

    public function testEachConsumerGetsItsOwnHandlerInCompiledContainer(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([], $container);
        $container->setDefinition(WebProfilerCspHandlerPass::SERVICE_ID, new Definition(stdClass::class));
        // Stand-ins for ProfilerController and WebDebugToolbarListener.
        $container->setDefinition('profiler_controller', $this->consumer());
        $container->setDefinition('toolbar_listener', $this->consumer());
        (new WebProfilerCspHandlerPass())->process($container);
        $container->compile();

        $controller = $container->get('profiler_controller');
        $listener = $container->get('toolbar_listener');
        self::assertInstanceOf(stdClass::class, $controller);
        self::assertInstanceOf(stdClass::class, $listener);
        self::assertNotSame($controller->csp, $listener->csp);
    }

    private function consumer(): Definition
    {
        $definition = new Definition(stdClass::class);
        $definition->setPublic(true);
        $definition->setProperty('csp', new Reference(WebProfilerCspHandlerPass::SERVICE_ID));

        return $definition;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function containerWithHandler(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoHttpHardeningExtension())->load([$config], $container);
        $container->setDefinition(WebProfilerCspHandlerPass::SERVICE_ID, new Definition(stdClass::class));

        return $container;
    }
}
