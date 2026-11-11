<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use ArrayObject;
use Nowo\HttpHardeningBundle\Event\RateLimitExceededEvent;
use Nowo\HttpHardeningBundle\EventSubscriber\RateLimitSubscriber;
use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;
use Nowo\HttpHardeningBundle\RateLimit\PerMinuteRateLimiter;
use Nowo\HttpHardeningBundle\RateLimit\RateLimitProviderInterface;
use Nowo\HttpHardeningBundle\RateLimit\RuleLimitProviderInterface;
use Nowo\HttpHardeningBundle\Tests\Fixtures\FixedRateLimitProvider;
use Nowo\HttpHardeningBundle\Tests\Fixtures\FixedRuleLimitProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The framework-free {@see RuleLimitProviderInterface} throttles exactly like the deprecated
 * {@see RateLimitProviderInterface}; a 429 dispatches {@see RateLimitExceededEvent}.
 */
final class RateLimitSubscriberPortsTest extends TestCase
{
    public function testBothProviderPortsThrottleIdentically(): void
    {
        foreach ([0, 1, 2, 3] as $limit) {
            $legacy = $this->subscriber(new FixedRateLimitProvider($limit));
            $new = $this->subscriber(new FixedRuleLimitProvider($limit));
            for ($i = 0; $i < 5; ++$i) {
                foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
                    $a = $this->event('/hooks/a', $ip);
                    $b = $this->event('/hooks/a', $ip);
                    $legacy($a);
                    $new($b);
                    self::assertSame($a->getResponse()?->getStatusCode(), $b->getResponse()?->getStatusCode(), "limit $limit, request $i, $ip");
                }
            }
        }
    }

    public function testRuleProviderReceivesTheRequestSnapshot(): void
    {
        $provider = new FixedRuleLimitProvider(5);
        $this->subscriber($provider)($this->event('/hooks/%61', '203.0.113.4'));

        self::assertCount(1, $provider->seen);
        $info = $provider->seen[0];
        self::assertSame('/hooks/a', $info->routerPath);
        self::assertSame('/hooks/%61', $info->pathInfo);
        self::assertSame('203.0.113.4', $info->clientIp);
        self::assertSame('GET', $info->method);
    }

    public function testTheFrameworkFreePortWinsWhenBothAreImplemented(): void
    {
        $provider = new class implements RateLimitProviderInterface, RuleLimitProviderInterface {
            public function limitPerMinute(string $rule, Request|HttpRequestInfo $request): int
            {
                return $request instanceof HttpRequestInfo ? 1 : 0;
            }
        };
        $subscriber = $this->subscriber($provider);
        $subscriber($this->event('/hooks/a'));
        $blocked = $this->event('/hooks/a');
        $subscriber($blocked);

        self::assertSame(429, $blocked->getResponse()?->getStatusCode());
    }

    public function testExceededRequestDispatchesAnEventWithoutTheRawIp(): void
    {
        $dispatcher = new EventDispatcher();
        /** @var ArrayObject<int, RateLimitExceededEvent> $seen */
        $seen = new ArrayObject();
        $dispatcher->addListener(RateLimitExceededEvent::class, static function (RateLimitExceededEvent $e) use ($seen): void {
            $seen->append($e);
        });
        $subscriber = new RateLimitSubscriber(
            new PerMinuteRateLimiter(new ArrayAdapter()),
            ['hooks' => ['path_prefixes' => ['/hooks'], 'path_patterns' => [], 'methods' => [], 'limit_per_minute' => 1]],
            [],
            $dispatcher,
        );

        $subscriber($this->event('/hooks/%61', '198.51.100.7'));
        self::assertCount(0, $seen, 'An accepted request is not reported.');

        $blocked = $this->event('/hooks/%61', '198.51.100.7');
        $subscriber($blocked);
        self::assertCount(1, $seen);
        $exceeded = $seen[0];
        self::assertInstanceOf(RateLimitExceededEvent::class, $exceeded);
        self::assertSame('hooks', $exceeded->rule);
        self::assertSame(PerMinuteRateLimiter::ipKey('198.51.100.7'), $exceeded->clientKey);
        self::assertStringNotContainsString('198.51.100.7', $exceeded->clientKey);
        self::assertSame(1, $exceeded->limitPerMinute);
        self::assertSame('/hooks/a', $exceeded->path);
        self::assertSame((string) $exceeded->retryAfterSeconds, $blocked->getResponse()?->headers->get('Retry-After'));
    }

    private function subscriber(RateLimitProviderInterface|RuleLimitProviderInterface $provider): RateLimitSubscriber
    {
        return new RateLimitSubscriber(
            new PerMinuteRateLimiter(new ArrayAdapter()),
            ['hooks' => ['path_prefixes' => ['/hooks'], 'path_patterns' => [], 'methods' => [], 'limit_per_minute' => 100]],
            ['hooks' => $provider],
        );
    }

    private function event(string $path, string $ip = '192.0.2.10'): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path, 'GET', [], [], [], ['REMOTE_ADDR' => $ip]),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
