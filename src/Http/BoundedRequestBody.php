<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Http;

use Nowo\HttpHardeningBundle\Http\Exception\RequestBodyTooLargeException;
use Symfony\Component\HttpFoundation\Request;

use function strlen;

/**
 * Reads a raw request body with a hard byte cap.
 *
 * `Request::getContent()` buffers the whole body before any size check, so a chunked upload
 * without `Content-Length` can fill worker memory. This rejects a declared oversize
 * `Content-Length` before reading a byte, then streams the body and stops one byte past the cap.
 * The edge (Caddy `request_body`, nginx `client_max_body_size`) stays the first line of defence.
 *
 * Stateless: static helpers only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class BoundedRequestBody
{
    /**
     * Returns the body (possibly '') or throws when it exceeds `$maxBytes` (values below 1 mean 1).
     *
     * @throws RequestBodyTooLargeException rendered as 413 Payload Too Large when not caught
     */
    public static function read(Request $request, int $maxBytes): string
    {
        $limit = max(1, $maxBytes);

        // Declared oversize: reject before reading a byte.
        if ((int) $request->headers->get('Content-Length', '0') > $limit) {
            throw new RequestBodyTooLargeException($limit, true);
        }

        $body = stream_get_contents($request->getContent(true), $limit + 1);
        if (false === $body) {
            return ''; // @codeCoverageIgnore
        }

        if (strlen($body) > $limit) {
            throw new RequestBodyTooLargeException($limit, false);
        }

        return $body;
    }

    /**
     * Same as {@see read()} but returns null instead of throwing when the body is too large.
     */
    public static function tryRead(Request $request, int $maxBytes): ?string
    {
        try {
            return self::read($request, $maxBytes);
        } catch (RequestBodyTooLargeException) {
            return null;
        }
    }
}
