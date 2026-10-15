<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Http;

use Nowo\HttpHardeningBundle\Http\Exception\JsonUploadException;
use Nowo\HttpHardeningBundle\Http\JsonUpload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

final class JsonUploadTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testDecodesAJsonObject(): void
    {
        self::assertSame(['schema' => 'x', 'n' => 1], JsonUpload::decodeObject($this->upload('{"schema":"x","n":1}')));
    }

    /**
     * @return iterable<string, array{string|null, int, string}>
     */
    public static function invalid(): iterable
    {
        yield 'list' => ['[1,2]', JsonUpload::DEFAULT_MAX_BYTES, JsonUploadException::INVALID_JSON];
        yield 'scalar' => ['"text"', JsonUpload::DEFAULT_MAX_BYTES, JsonUploadException::INVALID_JSON];
        yield 'syntax' => ['{"a":', JsonUpload::DEFAULT_MAX_BYTES, JsonUploadException::INVALID_JSON];
        yield 'declared size over cap' => ['{"a":"0123456789"}', 5, JsonUploadException::TOO_LARGE];
        yield 'no file' => [null, JsonUpload::DEFAULT_MAX_BYTES, JsonUploadException::MISSING_FILE];
    }

    #[DataProvider('invalid')]
    public function testRefusesBadUploads(?string $content, int $maxBytes, string $reason): void
    {
        try {
            JsonUpload::decodeObject(null === $content ? null : $this->upload($content), $maxBytes);
            self::fail('expected JsonUploadException');
        } catch (JsonUploadException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($reason, $e->getMessage());
        }
    }

    public function testBodyLargerThanTheDeclaredSizeStopsAtTheCap(): void
    {
        $path = $this->upload('{"padding":"'.str_repeat('x', 64).'"}')->getPathname();
        // Declared size under the cap (e.g. the file grew after the upload): the bounded read still refuses it.
        $file = new class($path) extends UploadedFile {
            public function __construct(string $path)
            {
                parent::__construct($path, 'x.json', 'application/json', UPLOAD_ERR_OK, true);
            }

            public function getSize(): int
            {
                return 2;
            }
        };

        $this->expectExceptionObject(new JsonUploadException(JsonUploadException::TOO_LARGE));
        JsonUpload::decodeObject($file, 16);
    }

    public function testUploadRemovedBeforeReadingIsMissing(): void
    {
        $path = $this->upload('{}')->getPathname();
        $file = new class($path) extends UploadedFile {
            public function __construct(string $path)
            {
                parent::__construct($path, 'x.json', 'application/json', UPLOAD_ERR_OK, true);
            }

            public function getSize(): int
            {
                return 2;
            }
        };
        unlink($path);

        $this->expectExceptionObject(new JsonUploadException(JsonUploadException::MISSING_FILE));
        JsonUpload::decodeObject($file);
    }

    public function testUnreadableOrInvalidUploadIsMissing(): void
    {
        $failed = new UploadedFile(__FILE__, 'x.json', 'application/json', UPLOAD_ERR_NO_FILE, true);
        $this->expectExceptionObject(new JsonUploadException(JsonUploadException::MISSING_FILE));
        JsonUpload::decodeObject($failed);
    }

    private function upload(string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'json-upload-');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return new UploadedFile($path, 'upload.json', 'application/json', UPLOAD_ERR_OK, true);
    }
}
