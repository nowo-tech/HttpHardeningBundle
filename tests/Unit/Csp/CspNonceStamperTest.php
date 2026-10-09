<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Csp;

use Nowo\HttpHardeningBundle\Csp\CspNonceStamper;
use PHPUnit\Framework\TestCase;

final class CspNonceStamperTest extends TestCase
{
    public function testStampsOnlyExecutableInlineBlocks(): void
    {
        self::assertSame(
            '<script nonce="n0nce">a()</script><script src="/x.js"></script><script type="application/ld+json">{}</script>'
            .'<script nonce="n0nce" type="text/javascript">b()</script><style nonce="n0nce" media="all">.a{}</style><STYLE nonce="n0nce">.b{}</STYLE>',
            CspNonceStamper::stamp(
                '<script>a()</script><script src="/x.js"></script><script type="application/ld+json">{}</script>'
                .'<script type="text/javascript">b()</script><style media="all">.a{}</style><STYLE>.b{}</STYLE>',
                'n0nce',
            ),
        );
    }

    public function testEscapesNonceAndShortCircuits(): void
    {
        self::assertSame('<script nonce="a&quot;b">x</script>', CspNonceStamper::stamp('<script>x</script>', 'a"b'));
        self::assertSame('<script>x</script>', CspNonceStamper::stamp('<script>x</script>', ''));
        self::assertSame('<p>plain</p>', CspNonceStamper::stamp('<p>plain</p>', 'n'));
    }
}
