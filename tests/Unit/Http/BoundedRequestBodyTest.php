<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\Http;

use Nowo\HttpHardeningBundle\Http\BoundedRequestBody;
use Nowo\HttpHardeningBundle\Http\Exception\RequestBodyTooLargeException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BoundedRequestBodyTest extends TestCase
{
    public function testReturnsBodyWithinCap(): void
    {
        self::assertSame('hello', BoundedRequestBody::read(Request::create('/', 'POST', content: 'hello'), 5));
        self::assertSame('', BoundedRequestBody::read(Request::create('/', 'POST', content: ''), 5));
        self::assertSame('hello', BoundedRequestBody::tryRead(Request::create('/', 'POST', content: 'hello'), 5));
    }

    public function testRejectsDeclaredOversizeBeforeReading(): void
    {
        $stream = $this->stream('x');
        $request = Request::create('/', 'POST', server: ['CONTENT_LENGTH' => '6'], content: $stream);

        try {
            BoundedRequestBody::read($request, 5);
            self::fail('Expected RequestBodyTooLargeException.');
        } catch (RequestBodyTooLargeException $e) {
            self::assertTrue($e->isDeclared());
            self::assertSame(5, $e->getMaxBytes());
            self::assertSame(413, $e->getStatusCode());
        }

        self::assertSame(0, ftell($stream), 'nothing read from the body');
        self::assertNull(BoundedRequestBody::tryRead($request, 5));
    }

    public function testRejectsChunkedBodyWithoutContentLength(): void
    {
        // Chunked transfer: no Content-Length; the read stops one byte past the cap.
        $stream = $this->stream(str_repeat('a', 10_000));
        $request = Request::create('/', 'POST', content: $stream);
        self::assertFalse($request->headers->has('Content-Length'));

        try {
            BoundedRequestBody::read($request, 5);
            self::fail('Expected RequestBodyTooLargeException.');
        } catch (RequestBodyTooLargeException $e) {
            self::assertFalse($e->isDeclared());
            self::assertSame('Request body exceeds 5 bytes.', $e->getMessage());
        }

        self::assertSame(6, ftell($stream), 'bounded read: cap + 1 bytes only');
    }

    public function testReadsChunkedBodyWithinCapWithoutContentLength(): void
    {
        $request = Request::create('/', 'POST', content: $this->stream('{"ok":true}'));

        self::assertSame('{"ok":true}', BoundedRequestBody::read($request, 11));
    }

    public function testUnderstatedContentLengthIsStillCapped(): void
    {
        $request = Request::create('/', 'POST', server: ['CONTENT_LENGTH' => '2'], content: 'abcdef');

        self::assertNull(BoundedRequestBody::tryRead($request, 5));
    }

    public function testCapBelowOneMeansOneByte(): void
    {
        self::assertNull(BoundedRequestBody::tryRead(Request::create('/', 'POST', content: 'ab'), 0));
        self::assertSame('a', BoundedRequestBody::read(Request::create('/', 'POST', content: 'a'), -3));
    }

    /**
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
