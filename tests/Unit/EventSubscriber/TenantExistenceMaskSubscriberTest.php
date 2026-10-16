<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use Nowo\HttpHardeningBundle\EventSubscriber\TenantExistenceMaskSubscriber;
use Nowo\HttpHardeningBundle\Tests\Fixtures\RecordingTenantAccessChecker as RecordingChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

final class TenantExistenceMaskSubscriberTest extends TestCase
{
    private const NAMED = '#^/projects/(?<tenant>[0-9a-f]{4})(?:/|$)#i';

    private const NUMBERED = '#^/orgs/([a-z]+)/(\d+)(?:/|$)#';

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function denials(): iterable
    {
        yield 'security AccessDeniedException' => [new AccessDeniedException()];
        yield 'AccessDeniedHttpException' => [new AccessDeniedHttpException()];
    }

    #[DataProvider('denials')]
    public function testStrangerGets404WithoutPrevious(Throwable $denial): void
    {
        $checker = new RecordingChecker([]);
        $event = $this->dispatch($denial, '/projects/ABCD/settings', 'stranger', $checker);

        $replaced = $event->getThrowable();
        self::assertInstanceOf(NotFoundHttpException::class, $replaced);
        self::assertNull($replaced->getPrevious(), 'No previous: the firewall would walk back to the AccessDeniedException.');
        self::assertSame('', $replaced->getMessage());
        self::assertSame([['stranger', 'ABCD']], $checker->calls, 'Raw capture is passed through.');
    }

    #[DataProvider('denials')]
    public function testMemberWithSomeAccessKeeps403(Throwable $denial): void
    {
        $event = $this->dispatch($denial, '/projects/abcd', 'member', new RecordingChecker(['member' => ['abcd']]));

        self::assertSame($denial, $event->getThrowable());
    }

    public function testAnonymousIsLeftAlone(): void
    {
        $denial = new AccessDeniedException();
        $checker = new RecordingChecker([]);
        $event = $this->dispatch($denial, '/projects/abcd', null, $checker);

        self::assertSame($denial, $event->getThrowable());
        self::assertSame([], $checker->calls);
    }

    public function testWithoutTokenStorageEveryoneIsAnonymous(): void
    {
        $denial = new AccessDeniedException();
        $subscriber = new TenantExistenceMaskSubscriber(null, new RecordingChecker([]), [self::NAMED]);
        $event = $this->event($denial, '/projects/abcd');
        $subscriber->onException($event);

        self::assertSame($denial, $event->getThrowable());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unmaskedPaths(): iterable
    {
        yield 'other area' => ['/admin/abcd'];
        yield 'lookalike prefix' => ['/projectsx/abcd'];
        yield 'id with suffix' => ['/projects/abcde'];
        yield 'collection' => ['/projects'];
    }

    #[DataProvider('unmaskedPaths')]
    public function testNonMatchingPathsAreLeftAlone(string $path): void
    {
        $denial = new AccessDeniedException();
        $checker = new RecordingChecker([]);
        $event = $this->dispatch($denial, $path, 'stranger', $checker);

        self::assertSame($denial, $event->getThrowable());
        self::assertSame([], $checker->calls);
    }

    public function testOtherExceptionsAreLeftAlone(): void
    {
        $error = new RuntimeException('boom');
        $checker = new RecordingChecker([]);
        $event = $this->dispatch($error, '/projects/abcd', 'stranger', $checker);

        self::assertSame($error, $event->getThrowable());
        self::assertSame([], $checker->calls);
    }

    public function testFirstCaptureGroupIsTheTenantWhenNoNamedGroup(): void
    {
        $checker = new RecordingChecker([]);
        $this->dispatch(new AccessDeniedException(), '/orgs/acme/42/billing', 'stranger', $checker);

        self::assertSame([['stranger', 'acme']], $checker->calls);
    }

    public function testTenantIdLookup(): void
    {
        $subscriber = new TenantExistenceMaskSubscriber(null, new RecordingChecker([]), [
            '#^/empty/(x?)/#',
            self::NAMED,
            self::NUMBERED,
        ]);

        self::assertSame('abcd', $subscriber->tenantId('/projects/abcd/issues'));
        self::assertSame('acme', $subscriber->tenantId('/orgs/acme/1'));
        self::assertNull($subscriber->tenantId('/empty//'), 'An empty capture never masks.');
        self::assertNull($subscriber->tenantId('/'));
    }

    private function dispatch(Throwable $throwable, string $path, ?string $username, RecordingChecker $checker): ExceptionEvent
    {
        $tokenStorage = new TokenStorage();
        if (null !== $username) {
            $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser($username, null, ['ROLE_USER']), 'main', ['ROLE_USER']));
        }
        $event = $this->event($throwable, $path);
        (new TenantExistenceMaskSubscriber($tokenStorage, $checker, [self::NAMED, self::NUMBERED]))->onException($event);

        return $event;
    }

    private function event(Throwable $throwable, string $path): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create($path), HttpKernelInterface::MAIN_REQUEST, $throwable);
    }
}
