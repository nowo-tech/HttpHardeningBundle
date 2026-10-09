<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Csp;

use function is_string;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Adds `nonce="…"` to inline `<script>` (executable types only, no `src`) and `<style>`
 * opening tags that do not declare one.
 *
 * Stamping cannot tell template markup from injected markup: only use it on whole responses of
 * layouts that never print untrusted HTML, or on trusted fragments (`|csp_stamp`).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CspNonceStamper
{
    private const TAG_PATTERN = '/<(script|style)(\s[^>]*)?>/i';

    private const NON_EXECUTABLE_TYPE_PATTERN = '/\btype\s*=\s*(["\'])(?!module\b|text\/javascript\b|application\/javascript\b|text\/ecmascript\b)[^"\']*\1/i';

    public static function stamp(string $html, string $nonce): string
    {
        if ('' === $nonce || (!str_contains($html, '<script') && !str_contains($html, '<style'))) {
            return $html;
        }

        $escapedNonce = htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $updated = preg_replace_callback(
            self::TAG_PATTERN,
            static function (array $matches) use ($escapedNonce): string {
                $attrs = $matches[2] ?? '';
                $isScript = 'script' === strtolower($matches[1]);
                if (1 === preg_match('/\bnonce\s*=/i', $attrs)
                    || ($isScript && 1 === preg_match('/\bsrc\s*=/i', $attrs))
                    || ($isScript && 1 === preg_match(self::NON_EXECUTABLE_TYPE_PATTERN, $attrs))) {
                    return $matches[0];
                }

                return '<'.$matches[1].' nonce="'.$escapedNonce.'"'.$attrs.'>';
            },
            $html,
        );

        return is_string($updated) ? $updated : $html;
    }
}
