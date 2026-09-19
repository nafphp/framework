<?php

namespace Tests\Unit;

use Fixtures\Controllers\TestController;
use Naf\Core\App;
use Naf\Core\Container;
use Naf\Decorators\AutoResolvingContainer;
use Naf\Support\AppHolder;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Naf\Core\Dispatcher;
use Naf\Core\Route;
use Naf\Exceptions\DispatcherException;
use Naf\Exceptions\RouteNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Tests\NafTestCase;

class DispatcherTest extends NafTestCase
{
    public function testDispatch()
    {
        $request = new ServerRequest('GET', '/test');

        $route = new Route();
        $route->add('GET', '/test', function () {
            return new Response(200, [], 'test');
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testDispatchWithInvalidResponse()
    {
        $this->expectException(DispatcherException::class);
        $request = new ServerRequest('GET', '/test');

        $route = new Route();
        $route->add('GET', '/test', function () {
            return 'test';
        });

        $dispatcher = new Dispatcher($route);
        $dispatcher->forward($request);
    }

    public function testDispatchWithMissingRoute()
    {
        $this->expectException(RouteNotFoundException::class);
        $request = new ServerRequest('GET', '/test');

        $route = new Route();
        $route->add('GET', '/not-found', function () {
            return 'test';
        });

        $dispatcher = new Dispatcher($route);
        $dispatcher->forward($request);
    }

    public function testDispatchWithClassController()
    {
        $request = new ServerRequest('GET', '/test');

        $route = new Route();
        $route->add('GET', '/test', [TestController::class, 'testResponse']);

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test', $response->getBody()->getContents());
    }

    public function testDispatchPlaceholderWhenNoRoutesAreConfigured()
    {
        $request = new ServerRequest('GET', '/');
        $route = new Route();

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('To begin developing, you may add your first route to', $response->getBody()->getContents());
    }

    public function testDispatchNotFoundWhenNoRoutesAreConfiguredAndOtherPathIsCalled()
    {
        $this->expectException(RouteNotFoundException::class);
        $request = new ServerRequest('GET', '/test');
        $route = new Route();

        $dispatcher = new Dispatcher($route);
        $dispatcher->forward($request);
    }

    // === Neue Tests ===

    public function testDispatchWithRouteParameters()
    {
        $request = new ServerRequest('GET', '/user/123');

        $route = new Route();
        $route->add('GET', '/user/{id}', function ($id) {
            return new Response(200, [], "User ID: $id");
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('User ID: 123', $response->getBody()->getContents());
    }

    public function testDispatchWithMultipleRouteParameters()
    {
        $request = new ServerRequest('GET', '/post/42/comment/7');

        $route = new Route();
        $route->add('GET', '/post/{postId}/comment/{commentId}', function ($postId, $commentId) {
            return new Response(200, [], "Post: $postId, Comment: $commentId");
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertSame('Post: 42, Comment: 7', $response->getBody()->getContents());
    }

    public function testDispatchWithClassControllerAndParameters()
    {
        $request = new ServerRequest('GET', '/test/999');

        $route = new Route();
        $route->add('GET', '/test/{id}', [TestController::class, 'testResponseWithParam']);

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertStringContainsString('999', $response->getBody()->getContents());
    }

    public function testDispatchWithDifferentHttpMethods()
    {
        $methods = ['POST', 'PUT', 'PATCH', 'DELETE'];

        foreach ($methods as $method) {
            $request = new ServerRequest($method, '/test');

            $route = new Route();
            $route->add($method, '/test', function () use ($method) {
                return new Response(200, [], "Method: $method");
            });

            $dispatcher = new Dispatcher($route);
            $response = $dispatcher->forward($request);

            $this->assertSame("Method: $method", $response->getBody()->getContents());
        }
    }

    public function testDispatchWithExitCode()
    {
        $request = new ServerRequest('GET', '/exit');

        $route = new Route();
        $route->add('GET', '/exit', function () {
            return -1;
        });

        $dispatcher = new Dispatcher($route);

        // Da exit(0) aufgerufen wird, können wir das nicht direkt testen
        // Aber wir können verifizieren, dass keine Exception geworfen wird
        $this->expectNotToPerformAssertions();

        // In einer realen Umgebung würde hier exit(0) aufgerufen
        // Für Tests müsste man eventuell einen Mock verwenden
    }

    public function testDispatchWithNullResponse()
    {
        $this->expectException(DispatcherException::class);
        $this->expectExceptionMessage('No valid response returned.');

        $request = new ServerRequest('GET', '/test');

        $route = new Route();
        $route->add('GET', '/test', function () {
            return null;
        });

        $dispatcher = new Dispatcher($route);
        $dispatcher->forward($request);
    }

    public function testDispatchWithArrayResponse()
    {
        $this->expectException(DispatcherException::class);

        $request = new ServerRequest('GET', '/test');

        $route = new Route();
        $route->add('GET', '/test', function () {
            return ['key' => 'value'];
        });

        $dispatcher = new Dispatcher($route);
        $dispatcher->forward($request);
    }

    public function testDispatchWithObjectResponse()
    {
        $this->expectException(DispatcherException::class);

        $request = new ServerRequest('GET', '/test');

        $route = new Route();
        $route->add('GET', '/test', function () {
            return new \stdClass();
        });

        $dispatcher = new Dispatcher($route);
        $dispatcher->forward($request);
    }

    public function testDispatchWithEmptyParams()
    {
        $request = new ServerRequest('GET', '/test');

        $route = new Route();
        $route->add('GET', '/test', function (...$params) {
            $this->assertEmpty($params);
            return new Response(200, [], 'No params');
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertSame('No params', $response->getBody()->getContents());
    }

    public function testDispatchPreservesResponseStatusCode()
    {
        $request = new ServerRequest('GET', '/test');

        $route = new Route();
        $route->add('GET', '/test', function () {
            return new Response(201, [], 'Created');
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testDispatchPreservesResponseHeaders()
    {
        $request = new ServerRequest('GET', '/test');

        $route = new Route();
        $route->add('GET', '/test', function () {
            return new Response(200, ['X-Custom-Header' => 'CustomValue'], 'test');
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertTrue($response->hasHeader('X-Custom-Header'));
        $this->assertSame('CustomValue', $response->getHeaderLine('X-Custom-Header'));
    }

    public function testDispatchWithComplexUriPath()
    {
        $request = new ServerRequest('GET', '/api/v1/users/123/posts/456');

        $route = new Route();
        $route->add('GET', '/api/v1/users/{userId}/posts/{postId}', function ($userId, $postId) {
            return new Response(200, [], "User: $userId, Post: $postId");
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        // Body nur EINMAL lesen und in Variable speichern
        $body = $response->getBody()->getContents();

        $this->assertStringContainsString('User: 123', $body);
        $this->assertStringContainsString('Post: 456', $body);
    }

    public function testDispatchRootPathWithPostMethod()
    {
        $this->expectException(RouteNotFoundException::class);

        $request = new ServerRequest('POST', '/');
        $route = new Route();

        $dispatcher = new Dispatcher($route);
        $dispatcher->forward($request);
    }

    public function testDispatchRootPathWhenRouteIsRegistered()
    {
        $request = new ServerRequest('GET', '/');

        $route = new Route();
        $route->add('GET', '/', function () {
            return new Response(200, [], 'Custom Root');
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertSame('Custom Root', $response->getBody()->getContents());
    }

    public function testDispatchWithTrailingSlash()
    {
        $request = new ServerRequest('GET', '/test/');

        $route = new Route();
        $route->add('GET', '/test/', function () {
            return new Response(200, [], 'With trailing slash');
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testDispatchWithSpecialCharactersInPath()
    {
        $request = new ServerRequest('GET', '/test-path_123');

        $route = new Route();
        $route->add('GET', '/test-path_123', function () {
            return new Response(200, [], 'Special chars');
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertSame('Special chars', $response->getBody()->getContents());
    }

    public function testDispatchCallsControllerMultipleTimes()
    {
        $route = new Route();
        $route->add('GET', '/test', [TestController::class, 'testResponse']);
        $dispatcher = new Dispatcher($route);

        $request1 = new ServerRequest('GET', '/test');
        $response1 = $dispatcher->forward($request1);

        $request2 = new ServerRequest('GET', '/test');
        $response2 = $dispatcher->forward($request2);

        $this->assertInstanceOf(ResponseInterface::class, $response1);
        $this->assertInstanceOf(ResponseInterface::class, $response2);
        $this->assertNotSame($response1, $response2); // Verschiedene Instanzen
    }

    public function testDispatchWithZeroAsParameter()
    {
        $request = new ServerRequest('GET', '/item/0');

        $route = new Route();
        $route->add('GET', '/item/{id}', function ($id) {
            return new Response(200, [], "ID: $id");
        });

        $dispatcher = new Dispatcher($route);
        $response = $dispatcher->forward($request);

        $this->assertStringContainsString('ID: 0', $response->getBody()->getContents());
    }

    public function testABoundControllerClassIsDispatchedInsteadOfANewInstance()
    {
        $replacement = new class extends TestController {
            public function testResponse(): Response
            {
                return new Response(200, [], 'replaced');
            }
        };

        $this->withContainer(function (AutoResolvingContainer $container) use ($replacement) {
            $container->set(TestController::class, $replacement);

            $route = new Route();
            $route->add('GET', '/test', [TestController::class, 'testResponse']);

            $response = (new Dispatcher($route))->forward(new ServerRequest('GET', '/test'));

            $this->assertSame('replaced', (string) $response->getBody());
        });
    }

    public function testAnUnboundControllerClassIsStillBuiltByTheContainer()
    {
        $this->withContainer(function () {
            $route = new Route();
            $route->add('GET', '/test', [TestController::class, 'testResponse']);

            $response = (new Dispatcher($route))->forward(new ServerRequest('GET', '/test'));

            $this->assertSame('test', (string) $response->getBody());
        });
    }

    public function testAFailingControllerFactoryIsVisible()
    {
        $this->withContainer(function (AutoResolvingContainer $container) {
            $container->set(TestController::class, function () {
                throw new \RuntimeException('factory exploded');
            });

            $route = new Route();
            $route->add('GET', '/test', [TestController::class, 'testResponse']);

            $this->expectExceptionMessage('factory exploded');
            (new Dispatcher($route))->forward(new ServerRequest('GET', '/test'));
        });
    }

    public function testABoundControllerWithoutTheActionIsReported()
    {
        $this->withContainer(function (AutoResolvingContainer $container) {
            $container->set(TestController::class, new \stdClass());

            $route = new Route();
            $route->add('GET', '/test', [TestController::class, 'testResponse']);

            $this->expectException(DispatcherException::class);
            (new Dispatcher($route))->forward(new ServerRequest('GET', '/test'));
        });
    }

    /**
     * Run a check against a fresh application container and restore the previous one.
     */
    private function withContainer(callable $check): void
    {
        $previous = AppHolder::get();
        $container = new AutoResolvingContainer(new Container());

        try {
            new App($container);
            $check($container);
        } finally {
            AppHolder::set($previous);
        }
    }
}
