<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Twig;

use Nowo\HttpHardeningBundle\Http\SafeInternalRedirect;
use Nowo\HttpHardeningBundle\Twig\SafeUrlExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SafeUrlExtensionTest extends TestCase
{
    public function testFunctionsResolveAgainstCurrentRequest(): void
    {
        $twig = new Environment(new ArrayLoader([
            'page' => '{{ safe_internal_path(next) }}|{{ safe_internal_path(evil, "/home") }}|{{ safe_href(js) ?? "none" }}|{{ safe_href("/ok") }}',
        ]));
        $stack = new RequestStack([Request::create('https://example.com/')]);
        $twig->addExtension(new SafeUrlExtension(new SafeInternalRedirect($stack)));

        self::assertSame('/settings|/home|none|/ok', $twig->render('page', [
            'next' => 'https://example.com/settings',
            'evil' => '//evil.example',
            'js' => 'javascript:alert(1)',
        ]));
    }
}
