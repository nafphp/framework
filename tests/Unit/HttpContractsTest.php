<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Naf\Core\App;
use Naf\Core\Container;
use Naf\Core\Event;
use Naf\Core\EventManager;
use Naf\Core\Route;
use Naf\Decorators\AutoResolvingContainer;
use Naf\Exceptions\MethodNotAllowedException;
use Naf\Support\AppHolder;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;
use Tests\NafTestCase;
use Throwable;
use TypeError;

final class HttpContractsTest extends NafTestCase
{
    private App $application;

    private mixed $previousApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = (new ReflectionProperty(AppHolder::class, 'instance'))->getValue();
        $this->application = new App(new AutoResolvingContainer(new Container()));
        $this->application->container()->set(LoggerInterface::class, new NullLogger());
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(AppHolder::class, 'instance'))->setValue(null, $this->previousApp);
        parent::tearDown();
    }

    public function testHandleReplacesRequestBindingsAndClearsTheMatchedRoute(): void
    {
        $route = $this->application->container()->get(Route::class);
        $route->add('GET', '/users/{id}', static fn(string $id) => \Naf\json([
            'id' => $id, 'query' => \Naf\param()->get('filter'),
        ]), 'users.show');

        $first    = (new ServerRequest('GET', '/users/42'))->withQueryParams(['filter' => 'first']);
        $response = $this->application->handle($first);
        self::assertSame(['id' => '42', 'query' => 'first'], json_decode((string) $response->getBody(), true));
        self::assertSame('users.show', $route->current());

        $second   = (new ServerRequest('GET', '/users/7'))->withQueryParams(['filter' => 'second']);
        $response = $this->application->handle($second);
        self::assertSame(['id' => '7', 'query' => 'second'], json_decode((string) $response->getBody(), true));
        self::assertSame($second, $this->application->container()->get(ServerRequestInterface::class));

        self::assertSame(404, $this->application->handle(new ServerRequest('GET', '/missing'))->getStatusCode());
        self::assertNull($route->current());
    }

    public function testMethodErrorsRetainAllowWhenAnExceptionListenerRendersJson(): void
    {
        $this->application->container()->get(Route::class)->add('GET', '/items', static fn() => new Response(), 'items');
        $this->application->container()->get(EventManager::class)->listen(Event::EXCEPTION, static function (Throwable $exception) {
            self::assertInstanceOf(MethodNotAllowedException::class, $exception);

            return \Naf\json(['error' => 'method'], 405);
        });

        $response = $this->application->handle(new ServerRequest('POST', '/items'));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeaderLine('Allow'));
        self::assertSame(['error' => 'method'], json_decode((string) $response->getBody(), true));
    }

    public function testAutomaticOptionsDoesNotInvokeTheGetHandler(): void
    {
        $this->application->container()->get(Route::class)->add('GET', '/items', static fn() => throw new RuntimeException('GET called'), 'items');
        $response = $this->application->handle(new ServerRequest('OPTIONS', '/items'));
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeaderLine('Allow'));
        self::assertSame('', (string) $response->getBody());
        self::assertSame(404, $this->application->handle(new ServerRequest('OPTIONS', '/missing'))->getStatusCode());
    }

    public function testExplicitHeadAndOptionsHandlersTakePrecedence(): void
    {
        $route = $this->application->container()->get(Route::class);
        $route->add('GET', '/items', static fn() => new Response(200, [], 'GET body'), 'get');
        $route->add('HEAD', '/items', static fn() => new Response(200, ['X-Handler' => 'head'], 'HEAD body'), 'head');
        $route->add('OPTIONS', '/items', static fn() => new Response(200, ['X-Handler' => 'options']), 'options');

        $response = $this->application->handle(new ServerRequest('HEAD', '/items'));
        self::assertSame('head', $response->getHeaderLine('X-Handler'));
        self::assertSame('9', $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());
        self::assertSame('options', $this->application->handle(new ServerRequest('OPTIONS', '/items'))->getHeaderLine('X-Handler'));
    }

    public function testHeadFallsBackToGetAndKeepsItsMetadata(): void
    {
        $this->application->container()->get(Route::class)->add('GET', '/items', static fn() => new Response(200, ['X-Handler' => 'get'], 'hello'), 'items');
        $response = $this->application->handle(new ServerRequest('HEAD', '/items'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('get', $response->getHeaderLine('X-Handler'));
        self::assertSame('5', $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testRequestAndFinalizationEventsRunOnceWithoutEmissionEvents(): void
    {
        $seen   = [];
        $events = $this->application->container()->get(EventManager::class);
        foreach ([Event::REQUEST_START, Event::CONTROLLER_CALLING, Event::CONTROLLER_CALLED, Event::RESPONSE_SEND, Event::RESPONSE_HEADER, Event::RESPONSE_END] as $event) {
            $events->listen($event, static function () use (&$seen, $event): void {
                $seen[] = $event;
            });
        }
        $this->application->container()->get(Route::class)->add('GET', '/items', static fn() => new Response(200), 'items');
        $this->application->handle(new ServerRequest('GET', '/items'));
        self::assertSame([Event::REQUEST_START, Event::CONTROLLER_CALLING, Event::CONTROLLER_CALLED, Event::RESPONSE_SEND], $seen);
    }

    public function testUrlParametersAreRequiredAndEncodingRemainsTheCallersResponsibility(): void
    {
        $route = new Route();
        $route->add('GET', '/items/{id}/{slug}', static fn() => new Response(), 'items');
        self::assertSame('/items/0/a%2Fb%3Fx%3D1', $route->url('items', ['id' => 0, 'slug' => rawurlencode('a/b?x=1')]));
        self::assertSame('/items/{slug}/literal', $route->url('items', ['id' => '{slug}', 'slug' => 'literal']));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing parameter 'slug' for route 'items'.");
        $route->url('items', ['id' => 42]);
    }

    public function testNonScalarUrlParameterIsRejected(): void
    {
        $route = new Route();
        $route->add('GET', '/items/{id}', static fn() => new Response(), 'items');
        $this->expectException(InvalidArgumentException::class);
        $route->url('items', ['id' => []]);
    }

    public function testReadOnlyPsrContainersAreRejectedByConstructorTypesBeforeBoot(): void
    {
        $readOnly = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('unused');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        foreach ([App::class, AutoResolvingContainer::class] as $class) {
            try {
                new $class($readOnly);
                self::fail('A writable container is required.');
            } catch (TypeError $exception) {
                self::assertStringContainsString($class . '::__construct()', $exception->getMessage());
                self::assertStringContainsString('MutableContainerInterface', $exception->getMessage());
            }

            self::assertSame($this->application, \Naf\app());
        }
    }

    public function testSeparateApplicationsCanHandleRequestsWithoutSharedTimers(): void
    {
        $second = new App(new Container());
        $second->container()->set(LoggerInterface::class, new NullLogger());
        $second->container()->get(Route::class)->add('GET', '/probe', static fn() => \Naf\response(\Naf\request()->getMethod()), 'probe');
        self::assertSame('GET', (string) $second->handle(new ServerRequest('GET', '/probe'))->getBody());
        self::assertSame(404, $this->application->handle(new ServerRequest('GET', '/probe'))->getStatusCode());
    }

    public function testHandlerSentinelCannotTerminateAnInProcessRequest(): void
    {
        $this->application->container()->get(Route::class)->add('GET', '/legacy', static fn() => -1, 'legacy');
        self::assertSame(500, $this->application->handle(new ServerRequest('GET', '/legacy'))->getStatusCode());
    }

    public function testRequestListenerFailureClearsRouteStateAndAllowsTheNextRequest(): void
    {
        $route = $this->application->container()->get(Route::class);
        $route->add('GET', '/items', static fn() => new Response(200), 'items');
        self::assertSame(200, $this->application->handle(new ServerRequest('GET', '/items'))->getStatusCode());
        $this->application->container()->get(EventManager::class)->listen(Event::REQUEST_START, static function ($request): void {
            if ($request->getUri()->getPath() === '/refused') {
                \Naf\abort(403);
            }
        });
        self::assertSame(403, $this->application->handle(new ServerRequest('GET', '/refused'))->getStatusCode());
        self::assertNull($route->current());
        self::assertSame(200, $this->application->handle(new ServerRequest('GET', '/items'))->getStatusCode());
    }
}
