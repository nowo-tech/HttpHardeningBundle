<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\EventSubscriber;

use Nowo\HttpHardeningBundle\Csp\CspNonceStamper;
use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

use function array_key_exists;
use function in_array;
use function is_string;

/**
 * Content-Security-Policy with a per-request nonce on HTML responses.
 *
 * On the main request a random nonce is stored in the `csp_nonce` request attribute (the shared
 * nowo-tech convention: every kit reads `app.request.attributes.get('csp_nonce')`). The nonce is
 * added to `script-src` and `style-src-elem`.
 *
 * Because a nonce in `script-src` disables `'unsafe-inline'`, inline `<script>` / `<style>` tags
 * without a nonce are stamped before the header is set. Stamping cannot tell template markup from
 * injected markup, so layouts that print editor/CMS HTML opt out with {@see self::STRICT_NONCE_MARKER}
 * on their `<html>` tag and nonce every inline block explicitly. Only the first `<html>` tag counts.
 *
 * In `kernel.debug` (unless debug relaxations are disabled) `script-src` gains `'unsafe-eval'`,
 * `style-src-elem` gains `'unsafe-inline'` and `connect-src` gains `ws: wss:` (Vite HMR, Web
 * Profiler). Runs before the Web Profiler toolbar listener so it can merge its own nonces.
 *
 * Registered by the extension on kernel.request (priority 1024) and kernel.response (priority -100).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ContentSecurityPolicySubscriber
{
    /** Request attribute holding the per-request nonce (shared nowo-tech convention). */
    public const REQUEST_ATTRIBUTE_NONCE = 'csp_nonce';

    /** Attribute on the layout's `<html>` tag that disables nonce stamping. */
    public const STRICT_NONCE_MARKER = 'data-csp-strict-nonce';

    /**
     * Directives in emission order with their default sources.
     */
    public const DEFAULT_DIRECTIVES = [
        'default-src' => ["'self'"],
        'base-uri' => ["'self'"],
        'object-src' => ["'none'"],
        'frame-ancestors' => ["'self'"],
        'form-action' => ["'self'"],
        'font-src' => ["'self'", 'data:'],
        'img-src' => ["'self'", 'data:', 'blob:'],
        'frame-src' => ["'self'"],
        'style-src-elem' => ["'self'"],
        'style-src-attr' => ["'unsafe-inline'"],
        'script-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'worker-src' => ["'self'", 'blob:'],
        'manifest-src' => ["'self'"],
    ];

    private const NONCE_DIRECTIVES = ['script-src', 'style-src-elem'];

    private const DEBUG_SOURCES = [
        'style-src-elem' => ["'unsafe-inline'"],
        'script-src' => ["'unsafe-eval'"],
        'connect-src' => ['ws:', 'wss:'],
    ];

    /**
     * @param array<string, list<string>>          $directives           directive name => base sources, in emission order
     * @param array<string, list<string>>          $extraSources         directive name => appended sources
     * @param iterable<CspSourceProviderInterface> $sourceProviders
     * @param list<string>                         $excludedPathPrefixes paths that never receive the header
     */
    public function __construct(
        private bool $kernelDebug = false,
        private array $directives = self::DEFAULT_DIRECTIVES,
        private array $extraSources = [],
        private iterable $sourceProviders = [],
        private array $excludedPathPrefixes = ['/_wdt', '/_profiler'],
        private bool $stampNonces = true,
        private bool $debugRelaxations = true,
        private bool $reportOnly = false,
    ) {
    }

    public function __invoke(RequestEvent|ResponseEvent $event): void
    {
        if ($event instanceof ResponseEvent) {
            $this->onResponse($event);

            return;
        }

        $this->onRequest($event);
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $attributes = $event->getRequest()->attributes;
        if (!$attributes->has(self::REQUEST_ATTRIBUTE_NONCE)) {
            $attributes->set(self::REQUEST_ATTRIBUTE_NONCE, base64_encode(random_bytes(16)));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->isExcludedPath($request->getPathInfo())) {
            return;
        }

        $response = $event->getResponse();
        $headerName = $this->reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        if ($response->headers->has($headerName) || !$this->isHtmlResponse($response)) {
            return;
        }

        $nonce = $request->attributes->get(self::REQUEST_ATTRIBUTE_NONCE, '');
        $nonce = is_string($nonce) ? $nonce : '';
        if ($this->stampNonces && !self::declaresStrictNonces($response)) {
            $content = $response->getContent();
            if (is_string($content)) {
                $updated = CspNonceStamper::stamp($content, $nonce);
                if ($updated !== $content) {
                    $response->setContent($updated);
                }
            }
        }

        $response->headers->set($headerName, $this->buildPolicy($request, $nonce));
    }

    public function buildPolicy(Request $request, string $nonce): string
    {
        $sources = [];
        foreach ($this->directives as $directive => $base) {
            $sources[$directive] = $base;
            if ('' !== $nonce && in_array($directive, self::NONCE_DIRECTIVES, true)) {
                $sources[$directive][] = "'nonce-".$nonce."'";
            }
            if ($this->kernelDebug && $this->debugRelaxations) {
                foreach (self::DEBUG_SOURCES[$directive] ?? [] as $source) {
                    $sources[$directive][] = $source;
                }
            }
            foreach ($this->extraSources[$directive] ?? [] as $source) {
                $sources[$directive][] = $source;
            }
        }

        foreach ($this->sourceProviders as $provider) {
            foreach ($provider->getSources($request) as $directive => $list) {
                if (!array_key_exists($directive, $sources)) {
                    continue;
                }
                foreach ($list as $source) {
                    $sources[$directive][] = $source;
                }
            }
        }

        $parts = [];
        foreach ($sources as $directive => $list) {
            $clean = [];
            foreach ($list as $source) {
                $source = trim($source);
                if ('' !== $source && !in_array($source, $clean, true)) {
                    $clean[] = $source;
                }
            }
            if ([] !== $clean) {
                $parts[] = $directive.' '.implode(' ', $clean);
            }
        }

        return implode('; ', $parts);
    }

    /**
     * True when the first `<html>` tag of the response carries {@see self::STRICT_NONCE_MARKER}.
     */
    public static function declaresStrictNonces(Response $response): bool
    {
        $content = $response->getContent();
        if (!is_string($content) || 1 !== preg_match('/<html\b[^>]*>/i', $content, $match)) {
            return false;
        }

        return 1 === preg_match('/\s'.self::STRICT_NONCE_MARKER.'(?:[\s=>]|$)/i', $match[0]);
    }

    private function isHtmlResponse(Response $response): bool
    {
        if (str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return true;
        }

        $content = $response->getContent();

        return is_string($content) && str_contains(substr($content, 0, 512), '<html');
    }

    private function isExcludedPath(string $path): bool
    {
        foreach ($this->excludedPathPrefixes as $prefix) {
            if ('' !== $prefix && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
