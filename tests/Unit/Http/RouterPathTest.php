<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Http;

use Nowo\HttpHardeningBundle\Http\RouterPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RouterPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'root' => ['/', '/'];
        yield 'plain' => ['/secrets/abc', '/secrets/abc'];
        yield 'encoded byte' => ['/%73ecrets/abc', '/secrets/abc'];
        yield 'encoded separator' => ['/secrets%2Fabc', '/secrets/abc'];
        yield 'decoded once only' => ['/%2573ecrets/abc', '/%73ecrets/abc'];
        yield 'plus is not a space' => ['/a+b', '/a+b'];
        yield 'query string ignored' => ['/secrets?x=%2F', '/secrets'];
    }

    #[DataProvider('paths')]
    public function testDecodesThePathInfoLikeTheRouter(string $uri, string $expected): void
    {
        self::assertSame($expected, RouterPath::of(Request::create($uri)));
    }
}
