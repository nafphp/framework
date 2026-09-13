<?php

namespace Tests\Unit;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\ServerRequest;
use Naf\Exceptions\AbortException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tests\NafTestCase;
use function Naf\abort;
use function Naf\app;

use function Naf\json;
use function Naf\redirect;
use function Naf\refresh;
use function Naf\request;

class FunctionsTest extends NafTestCase
{

    public function testFunctionRequest()
    {
        app()->container()->set(RequestInterface::class, function() { return new ServerRequest('GET', '/test'); });
        $this->assertInstanceOf(ServerRequestInterface::class, request());
    }
    
    public function testFunctionJson()
    {
        $response = json(['name' => 'test']);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(json_encode(['name' => 'test'], JSON_PRETTY_PRINT), $response->getBody()->getContents());
    }

    public function testFunctionJsonFail()
    {
        $this->expectException(\RuntimeException::class);
        $brokenData = ['file' => fopen(__FILE__, 'r')];
        $response = json($brokenData);
    }

    public function testFunctionRedirect()
    {
        $response = redirect('/test');
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('1.1', $response->getProtocolVersion());
        $this->assertSame('/test', $response->getHeaderLine('Location'));
        $this->assertSame('', (string) $response->getBody());
    }

    public function testRedirectUsesTheReasonPhraseForItsStatus(): void
    {
        $response = redirect('/saved', 303);
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('See Other', $response->getReasonPhrase());
        $this->assertSame('1.1', $response->getProtocolVersion());
    }

    public function testRefresh()
    {
        app()->container()->set(RequestInterface::class, function() { return new Request('GET', '/test'); });
        $response = refresh();
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/test', $response->getHeaderLine('Location'));
        $this->assertSame('1.1', $response->getProtocolVersion());
    }

    public function testAbort()
    {
        $this->expectException(AbortException::class);
        abort();
    }

}
