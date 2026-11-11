<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\EventSubscriber;

use Nowo\HttpHardeningBundle\Csp\CspSourceContributorInterface;
use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\HttpHardeningBundle\EventSubscriber\ContentSecurityPolicySubscriber;
use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function injections(): iterable
    {
        yield 'semicolon' => ["https://cdn.example; script-src 'unsafe-inline'"];
        yield 'space' => ["https://cdn.example 'unsafe-eval'"];
        yield 'newline' => ["https://cdn.example\nX-Injected: 1"];
        yield 'carriage return' => ["https://cdn.example\rX-Injected: 1"];
        yield 'comma' => ['https://cdn.example, script-src *'];
        yield 'tab' => ["https://cdn.example\t*"];
        yield 'nul' => ["https://cdn\0.example"];
    }

    /**
     * MJ-H-L1: a runtime source can neither add a directive / source / policy nor break the header, from either port.
     */
    #[DataProvider('injections')]
    public function testUnsafeRuntimeSourcesAreSkipped(string $source): void
    {
        $legacy = new class($source) implements CspSourceProviderInterface {
            public function __construct(private readonly string $source)
            {
            }

            public function getSources(Request $request): array
            {
                return ['img-src' => [$this->source, 'https://ok.example']];
            }
        };
        $contributor = new class($source) implements CspSourceContributorInterface {
            public function __construct(private readonly string $source)
            {
            }

            public function getSources(HttpRequestInfo $request): array
            {
                return ['img-src' => [$this->source, 'https://ok.example']];
            }
        };

        foreach ([new ContentSecurityPolicySubscriber(sourceProviders: [$legacy]), new ContentSecurityPolicySubscriber(sourceContributors: [$contributor])] as $subscriber) {
            $csp = $subscriber->buildPolicy(Request::create('/'), '');
            self::assertStringContainsString("img-src 'self' data: blob: https://ok.example;", $csp);
            self::assertStringNotContainsString('cdn.example', $csp);
            self::assertDoesNotMatchRegularExpression('/[\r\n\0,]/', $csp);
            self::assertStringNotContainsString('unsafe-eval', $csp);
        }
    }

    public function testConfiguredSourcesWithSeparatorsAreSkippedButSpacesKept(): void
    {
        $csp = (new ContentSecurityPolicySubscriber(extraSources: ['img-src' => ['https://a.example https://b.example', 'https://c.example; script-src *', "https://d.example\nX-Injected: 1"]]))
            ->buildPolicy(Request::create('/'), '');

        self::assertStringContainsString('https://a.example https://b.example', $csp, 'BC: a space-joined configured entry.');
        self::assertStringNotContainsString('c.example', $csp);
        self::assertStringNotContainsString('d.example', $csp);
    }
}
