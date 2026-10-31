<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional;

use Nowo\HttpHardeningBundle\Tests\Functional\App\RateLimitKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Full stack: the configured rules answer 429 + Retry-After before the controller runs; other paths are untouched.
 */
final class RateLimitTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return RateLimitKernel::class;
    }

    public function testConfiguredLimitAnswers429WithRetryAfter(): void
    {
        $client = $this->client();

        $client->request(Request::METHOD_POST, '/hooks/slack');
        self::assertResponseStatusCodeSame(200);
        $client->request(Request::METHOD_GET, '/hooks/teams');
        self::assertResponseStatusCodeSame(200);

        $client->request(Request::METHOD_POST, '/hooks/slack');
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame('{"message":"rate limit exceeded"}', $client->getResponse()->getContent());
        $retryAfter = (int) $client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(60, $retryAfter);

        $client->request(Request::METHOD_GET, '/');
        self::assertResponseStatusCodeSame(200, 'Unmatched paths are never limited.');
    }

    public function testAnotherClientIpHasItsOwnBucket(): void
    {
        $client = $this->client();

        for ($i = 0; $i < 3; ++$i) {
            $client->request(Request::METHOD_GET, '/hooks/a', server: ['REMOTE_ADDR' => '198.51.100.1']);
        }
        self::assertResponseStatusCodeSame(429);

        $client->request(Request::METHOD_GET, '/hooks/a', server: ['REMOTE_ADDR' => '198.51.100.2']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testRuntimeProviderLimitAndMethodFilter(): void
    {
        $client = $this->client();

        $client->request(Request::METHOD_POST, '/api/demo/ingest');
        self::assertResponseStatusCodeSame(200);
        $client->request(Request::METHOD_POST, '/api/demo/ingest');
        self::assertResponseStatusCodeSame(429, 'The provider limit is 1 per minute.');

        $client->request(Request::METHOD_GET, '/api/demo/ingest');
        self::assertResponseStatusCodeSame(200, 'GET is not covered by the rule.');
    }

    private function client(): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        // Buckets persist in cache.app (an array pool would be reset between requests like in a worker).
        $pool = $client->getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        return $client;
    }
}
