<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Model;

use DateTimeImmutable;
use Nowo\HttpHardeningBundle\Event\RateLimitExceededEvent;
use Nowo\HttpHardeningBundle\Event\TenantExistenceMaskedEvent;
use Nowo\HttpHardeningBundle\Http\HttpRequestInfoFactory;
use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;
use Nowo\HttpHardeningBundle\Model\RateLimitDecision;
use Nowo\HttpHardeningBundle\Model\UserRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ModelTest extends TestCase
{
    public function testUserRefNormalisesRoles(): void
    {
        $user = new UserRef('alice', ['r1' => 'ROLE_USER', 'ROLE_ADMIN']);

        self::assertSame('alice', $user->identifier);
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->roles);
        self::assertTrue($user->hasRole('ROLE_ADMIN'));
        self::assertFalse($user->hasRole('ROLE_SUPER_ADMIN'));
        self::assertSame([], (new UserRef('bob'))->roles);
    }

    public function testRequestInfoFromRequest(): void
    {
        $request = Request::create('https://shop.example:8443/%61pi/x?q=1', 'post', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->attributes->set('_route', 'api_x');
        $request->attributes->set('_route_params', ['project' => 'p1', 'n' => 3, 'nested' => ['no'], 7 => 'skip']);

        $info = HttpRequestInfoFactory::fromRequest($request);

        self::assertSame('POST', $info->method);
        self::assertSame('https', $info->scheme);
        self::assertSame('shop.example', $info->host);
        self::assertSame(8443, $info->port);
        self::assertSame('/%61pi/x', $info->pathInfo);
        self::assertSame('/api/x', $info->routerPath);
        self::assertSame('203.0.113.9', $info->clientIp);
        self::assertSame('api_x', $info->routeName);
        self::assertSame(['project' => 'p1', 'n' => 3], $info->routeParams);
        self::assertTrue($info->isSecure());
        self::assertSame($request->getSchemeAndHttpHost(), $info->schemeAndHttpHost());
    }

    public function testRequestInfoWithoutRoute(): void
    {
        $info = HttpRequestInfoFactory::fromRequest(Request::create('http://site.example/'));

        self::assertNull($info->routeName);
        self::assertSame([], $info->routeParams);
        self::assertFalse($info->isSecure());
        self::assertSame('http://site.example', $info->schemeAndHttpHost());
    }

    /**
     * The snapshot spells the origin exactly like Request::getSchemeAndHttpHost() (open-redirect checks use it).
     */
    public function testSchemeAndHttpHostMatchesTheRequest(): void
    {
        foreach (['http://a.example/', 'http://a.example:8080/', 'https://a.example/', 'https://a.example:80/', 'http://a.example:443/'] as $url) {
            $request = Request::create($url);
            self::assertSame($request->getSchemeAndHttpHost(), HttpRequestInfoFactory::fromRequest($request)->schemeAndHttpHost(), $url);
        }
        self::assertSame('https://b.example', (new HttpRequestInfo('GET', 'https', 'b.example', 443, '/', '/'))->schemeAndHttpHost());
    }

    public function testDecisionAndEvents(): void
    {
        $at = new DateTimeImmutable('2026-11-11 10:00:00');
        $decision = new RateLimitDecision(false, 5, 0, $at);
        self::assertFalse($decision->accepted);
        self::assertSame(5, $decision->limit);
        self::assertSame(0, $decision->remaining);
        self::assertSame($at, $decision->retryAfter);

        $exceeded = new RateLimitExceededEvent('hooks', 'ip_x', 5, '/hooks', 12);
        self::assertSame(['hooks', 'ip_x', 5, '/hooks', 12], [$exceeded->rule, $exceeded->clientKey, $exceeded->limitPerMinute, $exceeded->path, $exceeded->retryAfterSeconds]);

        $masked = new TenantExistenceMaskedEvent('abcd', '/projects/abcd', 'alice');
        self::assertSame(['abcd', '/projects/abcd', 'alice'], [$masked->tenantId, $masked->path, $masked->userIdentifier]);
    }
}
