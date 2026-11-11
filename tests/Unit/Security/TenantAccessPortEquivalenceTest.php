<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Security;

use Nowo\HttpHardeningBundle\Event\TenantExistenceMaskedEvent;
use Nowo\HttpHardeningBundle\EventSubscriber\TenantExistenceMaskSubscriber;
use Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface;
use Nowo\HttpHardeningBundle\Security\TenantAccessQueryInterface;
use Nowo\HttpHardeningBundle\Tests\Fixtures\BothPortsTenantAccessChecker;
use Nowo\HttpHardeningBundle\Tests\Fixtures\LegacyMapTenantAccessChecker;
use Nowo\HttpHardeningBundle\Tests\Fixtures\MapTenantAccessQuery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
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

use function sprintf;

/**
 * Tenant access is a permission decision: the framework-free {@see TenantAccessQueryInterface} must give exactly
 * the outcome of the deprecated {@see TenantAccessCheckerInterface} for the same host decision (404 vs untouched),
 * over every user / role / tenant / path / denial combination.
 */
final class TenantAccessPortEquivalenceTest extends TestCase
{
    private const PATTERNS = ['#^/projects/(?<tenant>[0-9a-f]{4})(?:/|$)#i', '#^/orgs/([a-z]+)/(\d+)(?:/|$)#'];

    private const ACCESS = ['member' => ['abcd'], 'multi' => ['abcd', 'beef'], 'orgmember' => ['acme']];

    private const OVERRIDE_ROLE = 'ROLE_SUPPORT';

    public function testBothPortsGiveTheSameOutcomeOverTheWholeMatrix(): void
    {
        $users = [
            'anonymous' => null,
            'stranger' => ['ROLE_USER'],
            'member' => ['ROLE_USER'],
            'multi' => ['ROLE_USER', 'ROLE_EDITOR'],
            'orgmember' => ['ROLE_USER'],
            'support' => ['ROLE_USER', self::OVERRIDE_ROLE],
            '' => ['ROLE_USER'],
        ];
        $paths = [
            '/projects/abcd', '/projects/ABCD/settings', '/projects/beef/x', '/projects/ffff', '/projects/%61bcd',
            '/projects/%2561bcd', '/projects/abcde', '/orgs/acme/1/billing', '/orgs/other/2', '/elsewhere/abcd',
        ];
        $denials = [AccessDeniedException::class, AccessDeniedHttpException::class, RuntimeException::class];

        $legacy = new LegacyMapTenantAccessChecker(self::ACCESS, self::OVERRIDE_ROLE);
        $query = new MapTenantAccessQuery(self::ACCESS, self::OVERRIDE_ROLE);
        $cases = 0;
        $masked = 0;
        foreach ($users as $username => $roles) {
            foreach ($paths as $path) {
                foreach ($denials as $denialClass) {
                    $legacyOutcome = $this->outcome($legacy, $path, null === $roles ? null : (string) $username, $roles ?? [], new $denialClass());
                    $queryOutcome = $this->outcome($query, $path, null === $roles ? null : (string) $username, $roles ?? [], new $denialClass());
                    self::assertSame($legacyOutcome, $queryOutcome, sprintf('user "%s", path %s, %s', $username, $path, $denialClass));
                    ++$cases;
                    $masked += 'masked' === $queryOutcome ? 1 : 0;
                }
            }
        }

        self::assertSame(7 * 10 * 3, $cases);
        self::assertGreaterThan(0, $masked, 'The matrix covers masked denials.');
    }

    public function testQueryReceivesIdentifierRolesAndRawTenant(): void
    {
        $query = new MapTenantAccessQuery([]);
        $event = $this->dispatch($query, '/projects/ABCD/settings', 'stranger', ['ROLE_USER', 'ROLE_EDITOR'], new AccessDeniedException());

        self::assertInstanceOf(NotFoundHttpException::class, $event->getThrowable());
        self::assertNull($event->getThrowable()->getPrevious());
        self::assertSame([['stranger', 'ABCD', ['ROLE_USER', 'ROLE_EDITOR']]], $query->calls);
    }

    public function testDenyByDefaultForUnknownTenants(): void
    {
        $event = $this->dispatch(new MapTenantAccessQuery([]), '/projects/0000', 'member', ['ROLE_USER'], new AccessDeniedException());

        self::assertInstanceOf(NotFoundHttpException::class, $event->getThrowable());
    }

    public function testTheFrameworkFreePortWinsWhenBothAreImplemented(): void
    {
        $denial = new AccessDeniedException();
        $kept = $this->dispatch(new BothPortsTenantAccessChecker(true), '/projects/abcd', 'u', ['ROLE_USER'], $denial);
        self::assertSame($denial, $kept->getThrowable());

        $masked = $this->dispatch(new BothPortsTenantAccessChecker(false), '/projects/abcd', 'u', ['ROLE_USER'], new AccessDeniedException());
        self::assertInstanceOf(NotFoundHttpException::class, $masked->getThrowable());
    }

    public function testMaskedDenialDispatchesAnEventWithScalarsOnly(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(TenantExistenceMaskedEvent::class, static function (TenantExistenceMaskedEvent $e) use (&$seen): void {
            $seen[] = [$e->tenantId, $e->path, $e->userIdentifier];
        });

        $this->dispatch(new MapTenantAccessQuery(['member' => ['abcd']]), '/projects/%61bcd/x', 'stranger', ['ROLE_USER'], new AccessDeniedException(), $dispatcher);
        $this->dispatch(new MapTenantAccessQuery(['member' => ['abcd']]), '/projects/abcd', 'member', ['ROLE_USER'], new AccessDeniedException(), $dispatcher);

        self::assertSame([['abcd', '/projects/abcd/x', 'stranger']], $seen, 'Only the masked denial is reported.');
    }

    /**
     * @param list<string> $roles
     */
    private function outcome(TenantAccessCheckerInterface|TenantAccessQueryInterface $checker, string $path, ?string $username, array $roles, Throwable $denial): string
    {
        $event = $this->dispatch($checker, $path, $username, $roles, $denial);
        $result = $event->getThrowable();
        if ($result === $denial) {
            return 'untouched';
        }

        return $result instanceof NotFoundHttpException && !$result->getPrevious() instanceof Throwable ? 'masked' : 'other';
    }

    /**
     * @param list<string> $roles
     */
    private function dispatch(TenantAccessCheckerInterface|TenantAccessQueryInterface $checker, string $path, ?string $username, array $roles, Throwable $denial, ?EventDispatcher $dispatcher = null): ExceptionEvent
    {
        $tokenStorage = new TokenStorage();
        if (null !== $username) {
            $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('' === $username ? 'x' : $username, null, $roles), 'main', $roles));
        }
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST, $denial);
        (new TenantExistenceMaskSubscriber($tokenStorage, $checker, self::PATTERNS, $dispatcher))->onException($event);

        return $event;
    }
}
