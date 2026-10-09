<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Twig;

use Nowo\HttpHardeningBundle\Csp\CspNonceStamper;
use Nowo\HttpHardeningBundle\EventSubscriber\ContentSecurityPolicySubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

use function is_string;

/**
 * `csp_nonce()` returns the per-request CSP nonce; `|csp_stamp` nonces trusted fragments
 * (third-party tag snippets) on strict-nonce layouts. Never pipe CMS / user HTML through it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CspNonceExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('csp_stamp', $this->stamp(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Nonce of the current request, falling back to the main request (sub-requests / fragments).
     */
    public function nonce(): string
    {
        foreach ([$this->requestStack->getCurrentRequest(), $this->requestStack->getMainRequest()] as $request) {
            if (!$request instanceof Request) {
                continue;
            }
            $nonce = $request->attributes->get(ContentSecurityPolicySubscriber::REQUEST_ATTRIBUTE_NONCE);
            if (is_string($nonce) && '' !== $nonce) {
                return $nonce;
            }
        }

        return '';
    }

    public function stamp(?string $html): string
    {
        return CspNonceStamper::stamp((string) $html, $this->nonce());
    }
}
