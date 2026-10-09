<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Command;

use Closure;
use Nowo\HttpHardeningBundle\Command\LintCspNoncesCommand;
use Nowo\HttpHardeningBundle\Csp\InlineNonceLinter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class LintCspNoncesCommandTest extends TestCase
{
    public function testDefaultsToTemplatesAndSucceeds(): void
    {
        $this->withProject(static function (string $projectDir): void {
            $tester = new CommandTester(new LintCspNoncesCommand(new InlineNonceLinter(), $projectDir));

            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('declares a CSP nonce', $tester->getDisplay());
            self::assertSame('nowo:http-hardening:lint-csp-nonces', (new LintCspNoncesCommand(new InlineNonceLinter(), $projectDir))->getName());
        });
    }

    public function testFailsWithFindingsForRelativeAndAbsoluteDirs(): void
    {
        $this->withProject(static function (string $projectDir): void {
            $tester = new CommandTester(new LintCspNoncesCommand(new InlineNonceLinter(), $projectDir.'/'));

            self::assertSame(Command::FAILURE, $tester->execute(['dirs' => ['templates/', $projectDir.'/other', '']]));
            $display = $tester->getDisplay();
            self::assertStringContainsString($projectDir.'/other/bad.html.twig:1  <script>', $display);
            self::assertStringContainsString('1 inline block(s) without a CSP nonce', $display);
        });
    }

    /**
     * @param Closure(string): void $test
     */
    private function withProject(Closure $test): void
    {
        $projectDir = sys_get_temp_dir().'/nowo-csp-cmd-'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/templates', 0o777, true);
        mkdir($projectDir.'/other', 0o777, true);
        file_put_contents($projectDir.'/templates/ok.html.twig', '<script nonce="{{ csp_nonce() }}">a()</script>');
        file_put_contents($projectDir.'/other/bad.html.twig', '<script>a()</script>');

        try {
            $test($projectDir);
        } finally {
            unlink($projectDir.'/templates/ok.html.twig');
            unlink($projectDir.'/other/bad.html.twig');
            rmdir($projectDir.'/templates');
            rmdir($projectDir.'/other');
            rmdir($projectDir);
        }
    }
}
