<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use Nowo\HttpHardeningBundle\EventSubscriber\AnonymousSessionCookieStripSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AnonymousSessionCookieStripSubscriberTest extends TestCase
{
    public function testStripsSessionCookieOnPublicAnonymousGet(): void
    {
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $subscriber = new AnonymousSessionCookieStripSubscriber(
            $tokenStorage,
            'APPSESSID',
            ['site_'],
            ['nowo_seo_kit_robots'],
        );

        $request = Request::create('https://example.com/');
        $request->attributes->set('_route', 'site_home');
        $response = new Response('ok');
        $response->headers->setCookie(Cookie::create('APPSESSID', 'abc'));
        $response->headers->setCookie(Cookie::create('consent', '1'));

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $subscriber($event);

        $names = array_map(static fn (Cookie $c): string => $c->getName(), $response->headers->getCookies());
        self::assertNotContains('APPSESSID', $names);
        self::assertContains('consent', $names);
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('120', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testKeepsSessionCookieForAuthenticatedUser(): void
    {
        $user = new InMemoryUser('admin', null, ['ROLE_USER']);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $subscriber = new AnonymousSessionCookieStripSubscriber(
            $tokenStorage,
            'APPSESSID',
            ['site_'],
            [],
        );

        $request = Request::create('https://example.com/');
        $request->attributes->set('_route', 'site_home');
        $response = new Response('ok');
        $response->headers->setCookie(Cookie::create('APPSESSID', 'abc'));

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $subscriber($event);

        $names = array_map(static fn (Cookie $c): string => $c->getName(), $response->headers->getCookies());
        self::assertContains('APPSESSID', $names);
    }
}
