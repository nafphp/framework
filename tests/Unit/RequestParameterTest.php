<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Support\RequestParameter;
use Nyholm\Psr7\ServerRequest;
use Tests\NafTestCase;

final class RequestParameterTest extends NafTestCase
{
    public function testMergesQueryParsedBodyAndJsonWithExpectedPrecedence(): void
    {
        $request = (new ServerRequest('POST', '/items', ['Content-Type' => 'Application/JSON; charset=utf-8'], '{"a":"from-json","nested":{"key":"val"},"nullKey":null}'))
            ->withQueryParams(['a' => 'from-query', 'q' => 'query-only', 0 => 'query-zero']);

        $param = new RequestParameter($request);

        self::assertSame('from-json', $param->get('a'));
        self::assertSame('query-only', $param->get('q'));
        self::assertSame('val', $param->get('nested.key'));
        self::assertSame('fallback', $param->get('nested.missing', 'fallback'));
        self::assertTrue($param->has('a'));
        self::assertTrue($param->has('nested.key'));
        self::assertTrue($param->has('nullKey'));
        self::assertNull($param->get('nullKey', 'fallback'));
        self::assertFalse($param->has('nested.missing'));
        self::assertFalse($param->has('unknown'));
    }

    public function testParsedBodyOverridesNumericAndStringQueryKeysWithoutReindexing(): void
    {
        $request = (new ServerRequest('POST', '/items'))
            ->withQueryParams([0 => 'query-zero', 'name' => 'query'])
            ->withParsedBody([0 => 'body-zero', 'name' => 'body']);

        $param = new RequestParameter($request);

        self::assertSame(['0' => 'body-zero', 'name' => 'body'], $param->all());
    }
}
