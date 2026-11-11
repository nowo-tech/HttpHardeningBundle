<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional;

use Nowo\HttpHardeningBundle\Tests\Functional\App\DddHost\SecurityEventLog;
use Nowo\HttpHardeningBundle\Tests\Functional\App\DddHostKernel;
use Nowo\HttpHardeningBundle\Tests\Functional\App\TenantMaskKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * REQ-ARCH-001 DDD host: a kernel wired only with the framework-free ports behaves like the standard host on the
 * deprecated ports (same status code for every user / path), throttles from the snapshot port, adds contributed CSP
 * sources and receives the scalar security events.
 */
final class DddHostKernelTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return DddHostKernel::class;
    }

    /**
     * @return iterable<array{string|null, string}>
     */
    private function matrix(): iterable
    {
        $paths = ['/projects/aaaa/settings', '/projects/aaaa/issues', '/projects/aaaa', '/projects/AAAA/settings',
            '/projects/bbbb/settings', '/projects/%61aaa/settings', '/projects/ffff/missing', '/elsewhere/aaaa'];
        foreach ([null, 'member', 'stranger'] as $user) {
            foreach ($paths as $path) {
                yield [$user, $path];
            }
        }
    }

    /**
     * Old-vs-new equivalence at the HTTP level: the standard host (deprecated `TenantAccessCheckerInterface`,
     * {@see TenantMaskKernel}) and this DDD host answer the same status for every user / path of the matrix.
     */
    public function testSameAnswerAsTheStandardHost(): void
    {
        $standard = $this->standardOutcomes();

        $ddd = [];
        foreach ($this->matrix() as [$user, $path]) {
            $client = $this->client($user);
            $client->request(Request::METHOD_GET, $path);
            $ddd[($user ?? '-').' '.$path] = $client->getResponse()->getStatusCode();
        }

        self::assertCount(24, $ddd);
        self::assertSame($standard, $ddd);
    }

    public function testStrangerIsMaskedAndTheEventIsRecorded(): void
    {
        $client = $this->client('stranger');
        $client->request(Request::METHOD_GET, '/projects/aaaa/settings');
        self::assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, '/projects/aaaa/settings', server: ['PHP_AUTH_USER' => 'member', 'PHP_AUTH_PW' => 'pw']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(['masked aaaa stranger'], $this->log($client)->entries);
    }

    public function testRuleLimitProviderThrottlesPerProject(): void
    {
        $client = $this->client(null);

        $client->request(Request::METHOD_POST, '/api/small/ingest');
        self::assertResponseStatusCodeSame(200);
        $client->request(Request::METHOD_POST, '/api/small/ingest');
        self::assertResponseStatusCodeSame(429);

        for ($i = 0; $i < 3; ++$i) {
            $client->request(Request::METHOD_POST, '/api/free/ingest');
            self::assertResponseStatusCodeSame(200, 'Quota 0 disables the rule.');
        }

        self::assertSame(['rate ingest 1'], $this->log($client)->entries);
    }

    public function testCspContributorIsAutoconfigured(): void
    {
        $client = $this->client(null);
        $client->request(Request::METHOD_GET, '/page');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('https://cdn.ddd-host.example', (string) $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testHostAdaptersImportNoSymfonyType(): void
    {
        $files = glob(__DIR__.'/App/DddHost/*.php') ?: [];
        self::assertCount(4, $files);
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/^use (Symfony|Doctrine)\\\\/m', (string) file_get_contents($file), $file);
        }
    }

    /**
     * Status codes of the standard host (deprecated port), from its own kernel booted next to this one.
     *
     * @return array<string, int>
     */
    private function standardOutcomes(): array
    {
        $kernel = new TenantMaskKernel('test', true);
        $kernel->boot();
        $outcomes = [];
        try {
            foreach ($this->matrix() as [$user, $path]) {
                $client = $kernel->getContainer()->get('test.client');
                self::assertInstanceOf(KernelBrowser::class, $client);
                $client->setServerParameters(null === $user ? [] : ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw']);
                $client->request(Request::METHOD_GET, $path);
                $outcomes[($user ?? '-').' '.$path] = $client->getResponse()->getStatusCode();
            }
        } finally {
            $kernel->shutdown();
        }
        self::assertContains(404, $outcomes);
        self::assertContains(403, $outcomes);
        self::assertContains(401, $outcomes);

        return $outcomes;
    }

    private function client(?string $user): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = self::createClient([], null === $user ? [] : ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw']);
        $client->disableReboot();
        $pool = $client->getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        return $client;
    }

    private function log(KernelBrowser $client): SecurityEventLog
    {
        $log = $client->getContainer()->get(SecurityEventLog::class);
        self::assertInstanceOf(SecurityEventLog::class, $log);

        return $log;
    }
}
