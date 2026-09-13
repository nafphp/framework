<?php
declare(strict_types=1);

namespace Naf\Core;

use Naf\Decorators\AutoResolvingContainer;
use Naf\Exceptions\DispatcherException;
use Naf\Exceptions\RouteNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use function Naf\app;
use function Naf\event;
use function Naf\simple_render;

/**
 * Core dispatcher that handles HTTP request routing and execution.
 *
 * Manages the routing of requests to appropriate handlers and
 * supports both controller classes and callable actions.
 */
class Dispatcher
{
    /**
     * Route instance used for matching requests to handlers.
     *
     * @var Route
     */
    private Route $route;

    /**
     * Initializes a new dispatcher instance.
     *
     * @param Route $route The route instance for matching and handling requests
     */
    public function __construct(Route $route)
    {
        $this->route = $route;
    }

    /**
     * Forwards the HTTP request to its matching route handler.
     *
     * Processes the request by:
     * - Matching the URI and method against registered routes
     * - Handling a special case for a root path ('/')
     * - Executing controller actions or callables
     * - Dispatching controller lifecycle events
     * - Validating and returning the response
     *
     * @param ServerRequestInterface $request The incoming HTTP request
     *
     * @return ResponseInterface Response from the route handler
     * @throws RouteNotFoundException If no matching route is found
     * @throws DispatcherException If handler returns invalid response
     */
    public function forward(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod() ?? 'GET';
        $uri = $request->getUri()->getPath();

        try {
            $route = $this->route->find($uri, $method);
        } catch (RouteNotFoundException $e) {
            if ($uri === '/' && $method === 'GET') {
                return simple_render(__DIR__ . '/../Resources/views/naf_welcome.phtml');
            }
            throw $e;
        }

        $response = null;
        $action = $route['action'];

        if (is_array($action)) {
            [$class, $classAction] = $action;
            $container = app()->container();

            $controller = $container instanceof AutoResolvingContainer
                ? $container->make($class)
                : new $class();

            event()->dispatch(Event::CONTROLLER_CALLING, $request, $controller, $action);
            $response = $controller->$classAction(...$route['params'] ?? null);
            event()->dispatch(Event::CONTROLLER_CALLED, $request, $controller, $action, $response);
        } else if (is_callable($action)) {
            event()->dispatch(Event::CONTROLLER_CALLING, $request, null, $action);
            $response = $action(...$route['params'] ?? null);
            event()->dispatch(Event::CONTROLLER_CALLED, $request, null, $action, $response);
        }

        if ($response instanceof ResponseInterface) return $response;
        if ($response === -1) exit(0);
        throw new DispatcherException('No valid response returned.');
    }

}