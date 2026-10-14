<?php

declare(strict_types=1);

namespace Naf\Core;

use Naf\Decorators\AutoResolvingContainer;
use Naf\Exceptions\DispatcherException;
use Naf\Exceptions\MethodNotAllowedException;
use Naf\Exceptions\RouteNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Naf\app;
use function Naf\event;
use function Naf\response;
use function Naf\simple_render;

/**
 * Core dispatcher that handles HTTP request routing and execution.
 *
 * Manages the routing of requests to appropriate handlers and
 * supports both controller classes and callable actions.
 */
class Dispatcher
{
    public function __construct(private Route $route)
    {
    }

    /**
     * Forwards the HTTP request to its matching route handler.
     *
     * @throws RouteNotFoundException If no matching route is found
     * @throws MethodNotAllowedException If the path does not accept the method
     * @throws DispatcherException If handler returns invalid response
     */
    public function forward(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $uri    = $request->getUri()->getPath();

        try {
            $route = $this->route->find($uri, $method);
        } catch (MethodNotAllowedException $e) {
            if ($method === 'OPTIONS') {
                return response('', 204, ['Allow' => implode(', ', $e->getAllowedMethods())]);
            }

            throw $e;
        } catch (RouteNotFoundException $e) {
            if ($uri === '/' && in_array($method, ['GET', 'HEAD'], true) && $this->route->all() === []) {
                return simple_render(__DIR__ . '/../Resources/views/naf_welcome.phtml');
            }
            throw $e;
        }

        $action     = $route['action'];
        $handler    = $action;
        $controller = null;

        if (is_array($action)) {
            [$class, $classAction] = $action;
            $container             = app()->container();

            // Application bindings take precedence over automatic construction.
            if ($container->has($class)) {
                $controller = $container->get($class);
            } elseif ($container instanceof AutoResolvingContainer) {
                $controller = $container->make($class);
            } else {
                $controller = new $class();
            }

            if (!is_object($controller) || !is_callable([$controller, $classAction])) {
                throw new DispatcherException(
                    sprintf('Controller %s has no callable action %s.', $class, $classAction),
                );
            }

            $handler = [$controller, $classAction];
        }

        event()->dispatch(Event::CONTROLLER_CALLING, $request, $controller, $action);
        $response = $handler(...$route['params']);
        event()->dispatch(Event::CONTROLLER_CALLED, $request, $controller, $action, $response);

        if (!$response instanceof ResponseInterface) {
            throw new DispatcherException('No valid response returned.');
        }

        return $response;
    }
}
