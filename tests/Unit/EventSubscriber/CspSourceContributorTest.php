<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use Nowo\HttpHardeningBundle\Csp\CspSourceContributorInterface;
use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\HttpHardeningBundle\EventSubscriber\ContentSecurityPolicySubscriber;
use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CspSourceContributorTest extends TestCase
{
    public function testContributorsAndProvidersBuildTheSamePolicy(): void
    {
        $legacy = new class implements CspSourceProviderInterface {
            public function getSources(Request $request): array
            {
                return ['img-src' => ['https://cdn.example'], 'unknown-src' => ['https://ignored.example']];
            }
        };
        $contributor = new class implements CspSourceContributorInterface {
            /** @var list<HttpRequestInfo> */
            public array $seen = [];

            public function getSources(HttpRequestInfo $request): array
            {
                $this->seen[] = $request;

                return ['img-src' => ['https://cdn.example'], 'unknown-src' => ['https://ignored.example']];
            }
        };
        $request = Request::create('https://shop.example/page');

        $old = (new ContentSecurityPolicySubscriber(sourceProviders: [$legacy]))->buildPolicy($request, 'n1');
        $new = (new ContentSecurityPolicySubscriber(sourceContributors: [$contributor]))->buildPolicy($request, 'n1');

        self::assertSame($old, $new);
        self::assertStringContainsString('https://cdn.example', $new);
        self::assertStringNotContainsString('ignored.example', $new);
        self::assertCount(1, $contributor->seen);
        self::assertSame('/page', $contributor->seen[0]->routerPath);
    }

    public function testProviderSourcesComeBeforeContributorSources(): void
    {
        $legacy = new class implements CspSourceProviderInterface {
            public function getSources(Request $request): array
            {
                return ['connect-src' => ['https://a.example']];
            }
        };
        $contributor = new class implements CspSourceContributorInterface {
            public function getSources(HttpRequestInfo $request): array
            {
                return ['connect-src' => ['https://b.example']];
            }
        };

        $csp = (new ContentSecurityPolicySubscriber(sourceProviders: [$legacy], sourceContributors: [$contributor]))
            ->buildPolicy(Request::create('/'), '');

        self::assertStringContainsString("connect-src 'self' https://a.example https://b.example", $csp);
    }

    public function testAServiceOnBothPortsIsAskedOnceThroughTheNewPort(): void
    {
        $both = new class implements CspSourceProviderInterface, CspSourceContributorInterface {
            /** @var list<string> */
            public array $calls = [];

            public function getSources(Request|HttpRequestInfo $request): array
            {
                $this->calls[] = $request::class;

                return ['img-src' => ['https://both.example']];
            }
        };
        $other = new class implements CspSourceContributorInterface {
            public int $calls = 0;

            public function getSources(HttpRequestInfo $request): array
            {
                ++$this->calls;

                return [];
            }
        };

        $csp = (new ContentSecurityPolicySubscriber(sourceProviders: [$both], sourceContributors: [$both, $other]))
            ->buildPolicy(Request::create('/'), '');

        self::assertSame([HttpRequestInfo::class], $both->calls);
        self::assertSame(1, $other->calls);
        self::assertStringContainsString('https://both.example', $csp);
    }
}
