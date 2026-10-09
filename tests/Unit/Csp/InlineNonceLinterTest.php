<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Csp;

use Nowo\HttpHardeningBundle\Csp\InlineNonceLinter;
use PHPUnit\Framework\TestCase;

final class InlineNonceLinterTest extends TestCase
{
    public function testFlagsInlineBlocksWithoutNonce(): void
    {
        $source = <<<'TWIG'
            {# injects a <style>body{}</style> at runtime #}
            <script>window.a = 1;</script>
            <style media="print">.x{}</style>
            <script nonce="{{ csp_nonce() }}">ok()</script>
            <STYLE NONCE="{{ csp_nonce() }}">.y{}</STYLE>
            TWIG;

        self::assertSame([
            ['line' => 2, 'tag' => '<script>'],
            ['line' => 3, 'tag' => '<style media="print">'],
        ], (new InlineNonceLinter())->lintSource($source));
    }

    public function testExemptsExternalJsonTemplateAndEmptyBlocks(): void
    {
        $source = <<<'TWIG'
            <script src="/app.js"></script>
            <script defer src="{{ asset('x.js') }}">/* legacy */</script>
            <script type="application/json">{"a":1}</script>
            <script type="application/ld+json">{"@type":"Thing"}</script>
            <script type='importmap'>{"imports":{}}</script>
            <script type="text/template"><p>x</p></script>
            <style>   </style>
            <script></script>
            TWIG;

        self::assertSame([], (new InlineNonceLinter())->lintSource($source));
    }

    public function testLintPathsWalksDirectoriesAndFiles(): void
    {
        $dir = sys_get_temp_dir().'/nowo-csp-lint-'.bin2hex(random_bytes(4));
        mkdir($dir.'/sub', 0o777, true);
        file_put_contents($dir.'/ok.html.twig', '<script nonce="{{ csp_nonce() }}">a()</script>');
        file_put_contents($dir.'/sub/bad.html.twig', "\n<style>.a{}</style>");
        file_put_contents($dir.'/sub/ignored.txt', '<script>a()</script>');
        $single = $dir.'/single.twig';
        file_put_contents($single, '<script>b()</script>');

        try {
            $findings = (new InlineNonceLinter())->lintPaths([$dir, $single, $dir.'/missing']);

            self::assertSame([
                ['file' => $single, 'line' => 1, 'tag' => '<script>'],
                ['file' => $dir.'/sub/bad.html.twig', 'line' => 2, 'tag' => '<style>'],
            ], $findings);
        } finally {
            foreach ([$dir.'/ok.html.twig', $dir.'/sub/bad.html.twig', $dir.'/sub/ignored.txt', $single] as $file) {
                unlink($file);
            }
            rmdir($dir.'/sub');
            rmdir($dir);
        }
    }
}
