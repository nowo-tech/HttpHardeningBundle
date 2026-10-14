<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Http\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function sprintf;

/**
 * Request body larger than the allowed cap; Symfony's error handling renders it as 413.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RequestBodyTooLargeException extends HttpException
{
    public function __construct(
        private readonly int $maxBytes,
        private readonly bool $declared,
    ) {
        parent::__construct(
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            sprintf('Request body exceeds %d bytes.', $maxBytes),
        );
    }

    public function getMaxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * True when the `Content-Length` header already exceeded the cap (nothing was read).
     */
    public function isDeclared(): bool
    {
        return $this->declared;
    }
}
