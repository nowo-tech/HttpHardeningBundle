<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use Nowo\HttpHardeningBundle\EventSubscriber\RateLimitSubscriber;
use Nowo\HttpHardeningBundle\RateLimit\PerMinuteRateLimiter;
use Nowo\HttpHardeningBundle\RateLimit\RateLimitProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RateLimitSubscriberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function paths(): iterable
    {
        yield 'prefix root' => ['GET', '/hooks/slack', 'hooks'];
        yield 'prefix child' => ['POST', '/hooks/slack/actions', 'hooks'];
        yield 'prefix lookalike' => ['GET', '/hooks/slackware', null];
        yield 'plain prefix with trailing slash' => ['GET', '/public/anything', 'public'];
        yield 'plain prefix root itself' => ['GET', '/public', null];
        yield 'pattern' => ['POST', '/api/abc/ingest', 'ingest'];
        yield 'pattern child' => ['POST', '/api/abc/ingest/batch', 'ingest'];
        yield 'pattern other method' => ['GET', '/api/abc/ingest', null];
        yield 'method lower case in request' => ['post', '/api/abc/ingest', 'ingest'];
        yield 'elsewhere' => ['GET', '/', null];
        yield 'prefix single encoded byte' => ['GET', '/hooks/%73lack', 'hooks'];
        yield 'prefix encoded slash' => ['GET', '/hooks%2Fslack', 'hooks'];
        yield 'prefix fully encoded' => ['GET', '/%68%6F%6F%6B%73/%73%6C%61%63%6B', 'hooks'];
        yield 'prefix double encoded' => ['GET', '/hooks/%2573lack', null];
        yield 'plain prefix encoded' => ['GET', '/%70ublic/anything', 'public'];
        yield 'pattern encoded' => ['POST', '/api/abc/%69ngest', 'ingest'];
        yield 'pattern on the raw path only' => ['GET', '/raw/%41', 'raw'];
    }

    #[DataProvider('paths')]
    public function testMatchingRule(string $method, string $path, ?string $expected): void
    {
        self::assertSame($expected, $this->subscriber()->matchingRule(Request::create($path, $method)));
    }

    public function testFirstMatchingRuleWins(): void
    {
        $subscriber = new RateLimitSubscriber($this->limiter(), [
            'narrow' => $this->rule(['/hooks/slack'], limit: 1),
            'wide' => $this->rule(['/hooks'], limit: 1),
        ]);

        self::assertSame('narrow', $subscriber->matchingRule(Request::create('/hooks/slack')));
        self::assertSame('wide', $subscriber->matchingRule(Request::create('/hooks/teams')));
    }

    public function testAnswers429WithRetryAfterOnceTheLimitIsExceeded(): void
    {
        $subscriber = $this->subscriber();

        for ($i = 0; $i < 2; ++$i) {
            $event = $this->event('/hooks/slack');
            $subscriber($event);
            self::assertNull($event->getResponse());
        }

        $event = $this->event('/hooks/slack');
        $subscriber($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('{"message":"rate limit exceeded"}', $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $retryAfter = (int) $response->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(60, $retryAfter);
    }

    public function testBucketsArePerClientIpAndPerRule(): void
    {
        $subscriber = new RateLimitSubscriber($this->limiter(), [
            'hooks' => $this->rule(['/hooks'], limit: 1),
            'api' => $this->rule(['/api'], limit: 1),
        ]);

        $subscriber($this->event('/hooks/a', ip: '198.51.100.1'));
        $blocked = $this->event('/hooks/a', ip: '198.51.100.1');
        $subscriber($blocked);
        self::assertSame(429, $blocked->getResponse()?->getStatusCode());

        $otherIp = $this->event('/hooks/a', ip: '198.51.100.2');
        $subscriber($otherIp);
        self::assertNull($otherIp->getResponse());

        $otherRule = $this->event('/api/x', ip: '198.51.100.1');
        $subscriber($otherRule);
        self::assertNull($otherRule->getResponse());
    }

    public function testSubRequestsAndUnmatchedPathsAreIgnored(): void
    {
        $subscriber = new RateLimitSubscriber($this->limiter(), ['hooks' => $this->rule(['/hooks'], limit: 1)]);

        for ($i = 0; $i < 3; ++$i) {
            $sub = $this->event('/hooks/a', main: false);
            $subscriber($sub);
            self::assertNull($sub->getResponse());

            $elsewhere = $this->event('/home');
            $subscriber($elsewhere);
            self::assertNull($elsewhere->getResponse());
        }
    }

    public function testZeroLimitDisablesTheRule(): void
    {
        $subscriber = new RateLimitSubscriber($this->limiter(), ['hooks' => $this->rule(['/hooks'], limit: 0)]);

        for ($i = 0; $i < 5; ++$i) {
            $event = $this->event('/hooks/a');
            $subscriber($event);
            self::assertNull($event->getResponse());
        }
    }

    public function testRuntimeLimitProviderReplacesTheConfiguredLimit(): void
    {
        $provider = new class implements RateLimitProviderInterface {
            public int $limit = 1;

            /** @var list<string> */
            public array $calls = [];

            public function limitPerMinute(string $rule, Request $request): int
            {
                $this->calls[] = $rule.' '.$request->getPathInfo();

                return $this->limit;
            }
        };

        $subscriber = new RateLimitSubscriber(
            $this->limiter(),
            ['hooks' => $this->rule(['/hooks'], limit: 100)],
            ['hooks' => $provider],
        );

        $subscriber($this->event('/hooks/a'));
        $blocked = $this->event('/hooks/a');
        $subscriber($blocked);
        self::assertSame(429, $blocked->getResponse()?->getStatusCode(), 'the provider limit (1) wins over the configured 100');

        $provider->limit = 0;
        $open = $this->event('/hooks/a');
        $subscriber($open);
        self::assertNull($open->getResponse(), 'the provider can disable the rule at runtime');
        self::assertSame(['hooks /hooks/a', 'hooks /hooks/a', 'hooks /hooks/a'], $provider->calls);
    }

    public function testMissingClientIpSharesTheUnknownBucket(): void
    {
        $subscriber = new RateLimitSubscriber($this->limiter(), ['hooks' => $this->rule(['/hooks'], limit: 1)]);

        $subscriber($this->event('/hooks/a', ip: null));
        $blocked = $this->event('/hooks/a', ip: null);
        $subscriber($blocked);

        self::assertSame(429, $blocked->getResponse()?->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loginSpellings(): iterable
    {
        yield 'plain' => ['/login'];
        yield 'single encoded byte' => ['/%6Cogin'];
        yield 'single encoded byte inside' => ['/l%6Fgin'];
        yield 'hex upper case' => ['/%6c%6F%67%69%6E'];
        yield 'fully encoded' => ['/%6C%6F%67%69%6E'];
        yield 'double encoded' => ['/%256Cogin'];
        yield 'lookalike' => ['/logins'];
        yield 'front controller' => ['/index.php/%6Cogin'];
    }

    /**
     * The throttle applies exactly when the router dispatches to the throttled route (FF-M2).
     */
    #[DataProvider('loginSpellings')]
    public function testLoginThrottleFollowsTheRouter(string $uri): void
    {
        $routes = new RouteCollection();
        $routes->add('login', new Route('/login'));
        $request = $this->sapiRequest($uri);
        try {
            $routed = 'login' === (new UrlMatcher($routes, new RequestContext('', 'POST')))->matchRequest($request)['_route'];
        } catch (ResourceNotFoundException) {
            $routed = false;
        }

        $subscriber = new RateLimitSubscriber($this->limiter(), ['login' => $this->rule(['/login'], [], ['POST'], 3)]);
        self::assertSame($routed ? 'login' : null, $subscriber->matchingRule($request));

        $codes = [];
        for ($i = 0; $i < 4; ++$i) {
            $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $this->sapiRequest($uri), HttpKernelInterface::MAIN_REQUEST);
            $subscriber($event);
            $codes[] = $event->getResponse()?->getStatusCode();
        }
        self::assertSame($routed ? [null, null, null, 429] : [null, null, null, null], $codes);
    }

    public function testEncodedAndPlainSpellingsShareOneBucket(): void
    {
        $subscriber = new RateLimitSubscriber($this->limiter(), [
            'login' => $this->rule(['/login'], [], ['POST'], 3),
            'ingest' => $this->rule([], ['#^/api/[^/]+/ingest$#'], ['POST'], 2),
        ]);

        $codes = [];
        foreach (['/login', '/%6Cogin', '/%6C%6F%67%69%6E', '/l%6Fgin'] as $uri) {
            $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $this->sapiRequest($uri), HttpKernelInterface::MAIN_REQUEST);
            $subscriber($event);
            $codes[] = $event->getResponse()?->getStatusCode();
        }
        self::assertSame([null, null, null, 429], $codes);

        $codes = [];
        foreach (['/api/x/%69ngest', '/api/x/ingest', '/%61pi/x/ingest'] as $uri) {
            $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $this->sapiRequest($uri), HttpKernelInterface::MAIN_REQUEST);
            $subscriber($event);
            $codes[] = $event->getResponse()?->getStatusCode();
        }
        self::assertSame([null, null, 429], $codes);
    }

    /**
     * A request built like a real SAPI one: raw `REQUEST_URI`, base path / front controller detected by Request.
     */
    private function sapiRequest(string $uri): Request
    {
        return new Request([], [], [], [], [], [
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/app/public/index.php',
            'PHP_SELF' => '/index.php',
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
            'REMOTE_ADDR' => '192.0.2.10',
        ]);
    }

    private function subscriber(): RateLimitSubscriber
    {
        return new RateLimitSubscriber($this->limiter(), [
            'hooks' => $this->rule(['/hooks/slack'], limit: 2),
            'public' => $this->rule(['/public/'], limit: 2),
            'ingest' => $this->rule([], ['#^/api/[^/]+/ingest(?:/|$)#'], ['POST'], 2),
            'raw' => $this->rule([], ['#^/raw/%41$#'], [], 2),
        ]);
    }

    private function limiter(): PerMinuteRateLimiter
    {
        return new PerMinuteRateLimiter(new ArrayAdapter());
    }

    /**
     * @param list<string> $prefixes
     * @param list<string> $patterns
     * @param list<string> $methods
     *
     * @return array{path_prefixes: list<string>, path_patterns: list<string>, methods: list<string>, limit_per_minute: int}
     */
    private function rule(array $prefixes, array $patterns = [], array $methods = [], int $limit = 60): array
    {
        return ['path_prefixes' => $prefixes, 'path_patterns' => $patterns, 'methods' => $methods, 'limit_per_minute' => $limit];
    }

    private function event(string $path, ?string $ip = '192.0.2.10', bool $main = true): RequestEvent
    {
        $server = null === $ip ? [] : ['REMOTE_ADDR' => $ip];
        $request = Request::create($path, 'GET', [], [], [], $server);
        if (null === $ip) {
            $request->server->remove('REMOTE_ADDR');
        }

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
    }
}
