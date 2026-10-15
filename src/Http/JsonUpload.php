<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Http;

use JsonException;
use Nowo\HttpHardeningBundle\Http\Exception\JsonUploadException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_is_list;
use function file_get_contents;
use function is_array;
use function is_readable;
use function json_decode;
use function max;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Decodes an uploaded JSON object (admin / settings imports) with a hard byte cap — the upload twin of
 * {@see BoundedRequestBody}: the declared size is checked first, then at most cap + 1 bytes are read.
 *
 * Stateless: static helpers only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class JsonUpload
{
    /** 2 MiB. */
    public const DEFAULT_MAX_BYTES = 2_097_152;

    /**
     * @return array<string, mixed> the decoded JSON object (a JSON list is refused)
     *
     * @throws JsonUploadException reason missing_file, too_large or invalid_json
     */
    public static function decodeObject(?UploadedFile $file, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new JsonUploadException(JsonUploadException::MISSING_FILE);
        }

        $limit = max(1, $maxBytes);
        $size = $file->getSize();
        if (false === $size || $size > $limit) {
            throw new JsonUploadException(JsonUploadException::TOO_LARGE);
        }

        $path = $file->getPathname();
        $raw = is_readable($path) ? file_get_contents($path, false, null, 0, $limit + 1) : false;
        if (false === $raw) {
            throw new JsonUploadException(JsonUploadException::MISSING_FILE);
        }
        if (strlen($raw) > $limit) {
            throw new JsonUploadException(JsonUploadException::TOO_LARGE);
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new JsonUploadException(JsonUploadException::INVALID_JSON);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new JsonUploadException(JsonUploadException::INVALID_JSON);
        }

        /* @var array<string, mixed> $payload */
        return $payload;
    }
}
