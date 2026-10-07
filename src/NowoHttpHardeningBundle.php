<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle;

use Nowo\HttpHardeningBundle\DependencyInjection\NowoHttpHardeningExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Public HTTP hardening: security headers and optional anonymous session-cookie strip.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
class NowoHttpHardeningBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            // @igor-ignore - Symfony Bundle caches Extension once at boot; not request-scoped state
            $this->extension = new NowoHttpHardeningExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
