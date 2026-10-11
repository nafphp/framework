<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Naf\Core\App;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ServerRequestTest extends TestCase
{
    public function testBrowserAuthorityReplacesTheListenPortAndHasExactlyOneHostHeader(): void
    {
        $previous = [$_SERVER, $_COOKIE, $_GET, $_POST, $_FILES];
        $app      = (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
        $create   = (new ReflectionClass(App::class))->getMethod('createServerRequest');

        try {
            $_COOKIE = ['session' => 'example'];
            $_GET    = ['draft' => '1'];
            $_POST   = [];
            $_FILES  = [];
            foreach ([
                ['https', 'board.example.test', 8443, null],
                ['http', 'board.example.test', 8080, null],
                ['https', 'board.example.test:9443', 8443, 9443],
                ['https', '[::1]', 8443, null],
                ['http', '[::1]:8081', 8080, 8081],
            ] as [$scheme, $host, $listenPort, $browserPort]) {
                $_SERVER = [
                    'REQUEST_METHOD' => 'GET', 'REQUEST_SCHEME' => $scheme,
                    'HTTP_HOST'      => $host, 'SERVER_PORT' => $listenPort,
                    'REQUEST_URI'    => '/settings?draft=1', 'QUERY_STRING' => 'draft=1',
                    'HTTP_ACCEPT'    => 'application/json',
                ];
                $request = $create->invoke($app);
                self::assertSame($scheme . '://' . $host . '/settings?draft=1', (string) $request->getUri());
                self::assertSame($browserPort, $request->getUri()->getPort());
                self::assertSame([$host], $request->getHeader('Host'));
                self::assertSame('application/json', $request->getHeaderLine('Accept'));
                self::assertSame(['session' => 'example'], $request->getCookieParams());
                self::assertSame(['draft' => '1'], $request->getQueryParams());
            }
            foreach (['other@board.example.test', 'board.example.test/path', 'board.example.test?query', 'board.example.test#fragment'] as $invalid) {
                $_SERVER['HTTP_HOST'] = $invalid;
                try {
                    $create->invoke($app);
                    self::fail('An invalid Host must not be reinterpreted as a different authority.');
                } catch (InvalidArgumentException $error) {
                    self::assertSame('Host must contain only an authority.', $error->getMessage());
                }
            }
        } finally {
            [$_SERVER, $_COOKIE, $_GET, $_POST, $_FILES] = $previous;
        }
    }
}
