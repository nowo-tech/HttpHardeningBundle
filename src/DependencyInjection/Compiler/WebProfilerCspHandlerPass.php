<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Makes WebProfilerBundle's `web_profiler.csp.handler` non-shared (dev/test only; no-op without it).
 *
 * The handler keeps a `cspDisabled` flag that `ProfilerController` sets on `/_wdt` and `/_profiler`
 * requests and never resets. Shared, the toolbar listener uses that same instance, so in a
 * long-running worker (FrankenPHP worker mode, RoadRunner, `kernel.reset` disabled) every page
 * served after the first profiler request loses its `Content-Security-Policy` header and the
 * toolbar's inline `<script>` renders without a nonce. Non-shared gives each consumer its own
 * handler; the profiler paths never get a CSP from this bundle anyway (`csp.excluded_path_prefixes`).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WebProfilerCspHandlerPass implements CompilerPassInterface
{
    public const SERVICE_ID = 'web_profiler.csp.handler';

    /** Build-time parameter (dot prefix: not dumped) set by the extension from `web_profiler.isolate_csp_handler`. */
    public const ENABLED_PARAMETER = '.nowo_http_hardening.web_profiler.isolate_csp_handler';

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasParameter(self::ENABLED_PARAMETER) && !$container->getParameter(self::ENABLED_PARAMETER)) {
            return;
        }

        if (!$container->hasDefinition(self::SERVICE_ID)) {
            return;
        }

        $container->getDefinition(self::SERVICE_ID)->setShared(false);
    }
}
