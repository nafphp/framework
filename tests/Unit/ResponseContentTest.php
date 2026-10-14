<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Core\ResponseEmitter;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class ResponseContentTest extends TestCase
{
    #[DataProvider('contentlessResponses')]
    public function testContentRulesDoNotReadTheOriginalStream(int $status, string $method, ?string $length): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects($this->never())->method('read');
        $stream->expects($this->never())->method('__toString');
        $stream->expects($this->never())->method('rewind');
        $response = new Response($status, ['Content-Length' => '99', 'Transfer-Encoding' => 'chunked'], $stream);
        $prepared = ResponseEmitter::prepare($response, $method);
        self::assertSame('', (string) $prepared->getBody());
        self::assertFalse($prepared->hasHeader('Transfer-Encoding'));
        self::assertSame($length === null ? [] : [$length], $prepared->getHeader('Content-Length'));
    }

    public static function contentlessResponses(): array
    {
        return [[103, 'GET', null], [204, 'GET', null], [205, 'GET', '0'], [304, 'GET', '99'], [200, 'HEAD', '99']];
    }

    public function testHeadWithUnknownStreamSizeDoesNotInventALength(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getSize')->willReturn(null);
        $stream->expects($this->never())->method('read');
        $response = ResponseEmitter::prepare(new Response(200, [], $stream), 'HEAD');
        self::assertFalse($response->hasHeader('Content-Length'));
        self::assertSame('', (string) $response->getBody());
        self::assertFalse(ResponseEmitter::prepare($response, 'HEAD')->hasHeader('Content-Length'));
    }

    public function testNormalResponseRetainsTheOriginalStream(): void
    {
        $response = new Response(200, [], 'hello');
        self::assertSame($response, ResponseEmitter::prepare($response, 'GET'));
    }
}
