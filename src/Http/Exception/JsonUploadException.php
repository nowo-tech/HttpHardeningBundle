<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Http\Exception;

use InvalidArgumentException;
use Nowo\HttpHardeningBundle\Http\JsonUpload;

/**
 * Raised by {@see JsonUpload::decodeObject()}; the message is one of the
 * reason constants so callers can map it to a translated flash.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class JsonUploadException extends InvalidArgumentException
{
    public const MISSING_FILE = 'missing_file';

    public const TOO_LARGE = 'too_large';

    public const INVALID_JSON = 'invalid_json';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
