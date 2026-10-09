<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Http;

use Nowo\HttpHardeningBundle\Http\SafeHref;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeHrefTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function hrefs(): iterable
    {
        yield 'null' => [null, null];
        yield 'blank' => ['  ', null];
        yield 'control' => ["/a\tb", null];
        yield 'javascript' => ['JavaScript:alert(1)', null];
        yield 'data' => ['data:text/html,x', null];
        yield 'vbscript' => ['vbscript:x', null];
        yield 'protocol relative' => ['//evil.example', null];
        yield 'backslash' => ['/\\evil', null];
        yield 'mailto' => ['mailto:a@b.c', null];
        yield 'absolute path' => [' /contact ', '/contact'];
        yield 'https' => ['https://example.com/x', 'https://example.com/x'];
        yield 'http upper' => ['HTTP://example.com', 'HTTP://example.com'];
        yield 'bare relative' => ['docs/intro?x=1#top', 'docs/intro?x=1#top'];
    }

    #[DataProvider('hrefs')]
    public function testResolve(?string $href, ?string $expected): void
    {
        self::assertSame($expected, SafeHref::resolve($href));
    }
}
