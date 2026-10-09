<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Command;

use Nowo\HttpHardeningBundle\Csp\InlineNonceLinter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Fails when a Twig template contains an inline `<script>` / `<style>` without a nonce.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(
    name: self::NAME,
    description: 'Lists inline <script>/<style> blocks in Twig templates that do not declare a CSP nonce',
)]
final class LintCspNoncesCommand extends Command
{
    public const NAME = 'nowo:http-hardening:lint-csp-nonces';

    public function __construct(
        private readonly InlineNonceLinter $linter,
        private readonly string $projectDir,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dirs', InputArgument::IS_ARRAY, 'Directories or files to scan (relative to the project dir)', ['templates/'])
            ->setHelp(<<<'HELP'
                Scans <comment>*.twig</comment> files for inline <comment><script></comment> / <comment><style></comment> blocks with a body
                and no <comment>nonce</comment> attribute. Twig comments are ignored. External scripts (<comment>src=</comment>),
                JSON / JSON-LD / importmap / text/template islands and empty blocks are exempt.

                  <info>php %command.full_name%</info>
                  <info>php %command.full_name% templates/ vendor/acme/kit/templates</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dirs = $input->getArgument('dirs');
        $paths = [];
        foreach (is_array($dirs) ? $dirs : [] as $dir) {
            if (is_string($dir) && '' !== $dir) {
                $paths[] = str_starts_with($dir, '/') ? $dir : rtrim($this->projectDir, '/').'/'.$dir;
            }
        }

        $findings = $this->linter->lintPaths($paths);
        if ([] === $findings) {
            $io->success('Every inline <script>/<style> block declares a CSP nonce.');

            return Command::SUCCESS;
        }

        foreach ($findings as $finding) {
            $io->writeln(sprintf('%s:%d  %s', $finding['file'], $finding['line'], $finding['tag']), OutputInterface::OUTPUT_RAW);
        }
        $io->error(sprintf('%d inline block(s) without a CSP nonce (blocked on strict-nonce layouts). Add nonce="{{ csp_nonce() }}".', count($findings)));

        return Command::FAILURE;
    }
}
