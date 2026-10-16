<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional;

use Nowo\HttpHardeningBundle\Tests\Functional\App\TenantMaskKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Full stack (real firewall ExceptionListener): a signed-in stranger cannot tell an existing tenant from an
 * unknown one; a member with a too-low role keeps 403; anonymous visitors still hit the entry point.
 */
final class TenantExistenceMaskTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return TenantMaskKernel::class;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deniedPaths(): iterable
    {
        yield 'voter (AccessDeniedException)' => ['/projects/aaaa/settings'];
        yield 'guard (AccessDeniedHttpException)' => ['/projects/aaaa/issues'];
        yield 'tenant root' => ['/projects/aaaa'];
        yield 'upper-case id' => ['/projects/AAAA/settings'];
    }

    #[DataProvider('deniedPaths')]
    public function testStrangerGets404LikeAnUnknownTenant(string $path): void
    {
        $client = $this->client('stranger');

        $client->request(Request::METHOD_GET, $path);
        self::assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, '/projects/ffff/missing');
        self::assertResponseStatusCodeSame(404, 'An unknown tenant answers the same.');
    }

    #[DataProvider('deniedPaths')]
    public function testMemberWithATooLowRoleKeeps403(string $path): void
    {
        $this->client('member')->request(Request::METHOD_GET, $path);

        self::assertResponseStatusCodeSame(403);
    }

    public function testMemberOfAnotherTenantIsAStrangerHere(): void
    {
        $this->client('member')->request(Request::METHOD_GET, '/projects/bbbb/settings');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousStillHitsTheEntryPoint(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/projects/aaaa/settings');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHasHeader('WWW-Authenticate');
    }

    public function testNonMatchingPathsKeep403(): void
    {
        $this->client('stranger')->request(Request::METHOD_GET, '/elsewhere/aaaa');

        self::assertResponseStatusCodeSame(403);
    }

    private function client(string $username): KernelBrowser
    {
        return self::createClient([], ['PHP_AUTH_USER' => $username, 'PHP_AUTH_PW' => 'pw']);
    }
}
