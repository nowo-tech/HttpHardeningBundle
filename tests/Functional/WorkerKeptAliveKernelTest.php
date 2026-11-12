<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional;

use Nowo\HttpHardeningBundle\Tests\Functional\App\KeptAliveKernel;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function gc_collect_cycles;
use function memory_get_usage;
use function preg_match;
use function str_contains;

/**
 * FrankenPHP worker mode with the kernel kept alive (FRANKENPHP_RESET_KERNEL=false): ONE booted kernel serves 1,200
 * requests through `http_kernel` (handle + terminate), alternating two client IPs, and `services_resetter` is never
 * called (`Kernel::handle()` would run it, so the test goes through the `http_kernel` service). Every response carries
 * its own client IP and its own CSP nonce, the two IPs keep independent rate-limit buckets, and memory stays flat.
 */
final class WorkerKeptAliveKernelTest extends TestCase
{
    private const REQUESTS = 1200;

    private const WARMUP = 200;

    public function testAKeptAliveKernelKeepsRequestsIsolatedWithFlatMemory(): void
    {
        $kernel = new KeptAliveKernel('test', false);
        $kernel->boot();

        try {
            $pool = $kernel->getContainer()->get('cache.app');
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            $pool->clear();

            $httpKernel = $kernel->getContainer()->get('http_kernel');
            self::assertInstanceOf(HttpKernelInterface::class, $httpKernel);
            self::assertInstanceOf(TerminableInterface::class, $httpKernel);

            $nonces = [];
            $served = ['198.51.100.1' => 0, '198.51.100.2' => 0];
            $baseline = 0;

            for ($i = 0; $i < self::REQUESTS; ++$i) {
                $ip = 0 === $i % 2 ? '198.51.100.1' : '198.51.100.2';
                ++$served[$ip];
                $request = Request::create('/hooks/slack', Request::METHOD_POST, server: ['REMOTE_ADDR' => $ip]);
                $response = $httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
                $httpKernel->terminate($request, $response);

                // Each IP has its own bucket of 400: the other visitor's traffic never counts against it.
                $expected = $served[$ip] <= 400 ? 200 : 429;
                self::assertSame($expected, $response->getStatusCode(), $ip.' #'.$served[$ip]);

                // Security headers are set on every response, throttled or not.
                self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

                if (200 === $expected) {
                    self::assertSame(1, preg_match('#<p>([^|]+)\|([^<]+)</p>#', (string) $response->getContent(), $m));
                    [, $bodyIp, $nonce] = $m;
                    self::assertSame($ip, $bodyIp, 'the response carries its own client IP');
                    self::assertArrayNotHasKey($nonce, $nonces, 'a CSP nonce is never reused');
                    $nonces[$nonce] = true;
                    $csp = (string) $response->headers->get('Content-Security-Policy');
                    self::assertTrue(str_contains($csp, "'nonce-".$nonce."'"), 'the CSP header holds this request\'s nonce');
                }

                if (self::WARMUP === $i) {
                    gc_collect_cycles();
                    $baseline = memory_get_usage();
                }
            }

            self::assertCount(800, $nonces);
            gc_collect_cycles();
            self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'Memory must stay flat after warm-up.');
        } finally {
            $kernel->shutdown();
        }
    }
}
