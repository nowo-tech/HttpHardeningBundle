<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Csp;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function is_dir;
use function is_file;
use function substr_count;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;

/**
 * Finds inline `<script>` / `<style>` blocks in Twig templates that do not declare a nonce.
 *
 * On strict-nonce layouts (`data-csp-strict-nonce`) nothing is stamped at runtime, so a block
 * without `nonce="{{ csp_nonce() }}"` is blocked by the browser. External scripts (`src=`),
 * JSON / JSON-LD / import-map / template islands and empty placeholders are exempt.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class InlineNonceLinter
{
    private const BLOCK_PATTERN = '#<(script|style)\b([^>]*)>(.*?)</\1>#is';

    private const EXEMPT_SCRIPT_PATTERN = '#\bsrc\s*=|\btype\s*=\s*["\']?(application/(ld\+)?json|text/template|importmap)#i';

    /**
     * @return list<array{line: int, tag: string}>
     */
    public function lintSource(string $source): array
    {
        // Twig comments describe tags ("injects a <style>") without rendering them; keep line numbers.
        $source = (string) preg_replace_callback(
            '/\{#.*?#\}/s',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $source,
        );

        preg_match_all(self::BLOCK_PATTERN, $source, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $findings = [];
        foreach ($blocks as $block) {
            [$whole, $tag, $attributes, $body] = [$block[0], $block[1][0], $block[2][0], $block[3][0]];
            if (1 === preg_match('/\bnonce\b/i', $attributes) || '' === trim($body)) {
                continue;
            }
            if ('script' === strtolower($tag) && 1 === preg_match(self::EXEMPT_SCRIPT_PATTERN, $attributes)) {
                continue;
            }

            $findings[] = [
                'line' => substr_count($source, "\n", 0, $whole[1]) + 1,
                'tag' => '<'.$tag.$attributes.'>',
            ];
        }

        return $findings;
    }

    /**
     * Lints every `*.twig` file under the given paths (directories or single files).
     *
     * @param list<string> $paths
     *
     * @return list<array{file: string, line: int, tag: string}>
     */
    public function lintPaths(array $paths): array
    {
        $findings = [];
        foreach ($this->collectFiles($paths) as $file) {
            foreach ($this->lintSource((string) file_get_contents($file)) as $finding) {
                $findings[] = ['file' => $file] + $finding;
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function collectFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }
}
