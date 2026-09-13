<?php

declare(strict_types=1);

namespace Naf\Core;

use Naf\Exceptions\RouteNotFoundException;
use function Naf\event;

class Route
{
    protected array $routes = [];
    private ?string $currentName = null;

    /**
     * Adds a new route.
     *
     * @param string         $method
     * @param string         $path
     * @param array|callable $action
     * @param string|null    $name
     *
     * @return $this
     */
    public function add(string $method, string $path, array|callable $action, ?string $name = null): Route
    {
        if (count($this->routes) > 0 && empty($name)) {
            throw new \LogicException('You can\'t add routes without a name when there is more than one route configured.');
        }

        $this->routes[$name] = [
            'method' => $method,
            'path'   => $path,
            'action' => $action
        ];

        return $this;
    }

    /**
     * Finds a route matching the given URI and method.
     *
     * @param string $uri
     * @param string $method
     *
     * @return array|null
     * @throws RouteNotFoundException
     */
    public function find(string $uri, string $method): ?array
    {
        event()->dispatch(Event::ROUTE_MATCHING, $uri, $method);
        foreach ($this->routes as $name => $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            $pattern = '#^' . self::pattern($route['path']) . '$#';
            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                preg_match_all('#\{([^}]+)\}#', $route['path'], $paramNames);
                $params = array_combine($paramNames[1], $matches);
                $this->currentName = $name;
                event()->dispatch(Event::ROUTE_MATCHED, $route);
                return ['action' => $route['action'], 'params' => $params, 'name' => $name,];
            }
        }
        event()->dispatch(Event::ROUTE_NOT_FOUND, $uri, $method);
        throw new RouteNotFoundException();
    }

    /**
     * Builds the matching expression for a route path.
     *
     * Everything outside a {placeholder} is a literal and is escaped as one.
     * Without that, regex metacharacters in a path are still metacharacters: a
     * route for `/.well-known/jwks.json` would also answer `/Xwell-known/jwksXjson`,
     * and one for `/admin.php` would answer `/adminXphp`.
     *
     * Splitting on the placeholder rather than replacing it keeps the two apart.
     * With a single capturing group, PREG_SPLIT_DELIM_CAPTURE puts the delimiters
     * at the odd indices, so which pieces are placeholders needs no second guess.
     *
     * @param string $path
     *
     * @return string
     */
    private static function pattern(string $path): string
    {
        $segments = preg_split('#(\{[^}]+\})#', $path, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($segments === false) {
            return preg_quote($path, '#');
        }

        $pattern = '';

        foreach ($segments as $index => $segment) {
            $pattern .= $index % 2 === 1 ? '([^/]+)' : preg_quote($segment, '#');
        }

        return $pattern;
    }

    /**
     * Generates a URL for a given route name and parameters.
     *
     * @param string $name
     * @param array<string, int|string>  $params
     *
     * @return string
     * @throws RouteNotFoundException
     */
    public function url(string $name, array $params = []): string
    {
        foreach ($this->routes as $routeName => $route) {
            if ($routeName === $name) {
                $url = $route['path'];
                foreach ($params as $key => $value) {
                    $url = str_replace('{' . $key . '}', (string)$value, $url);
                }
                return $url;
            }
        }
        throw new RouteNotFoundException("Route '{$name}' not found.");
    }

    /**
     * Retrieves all routes.
     *
     * @return array An array of all routes.
     */
    public function all(): array
    {
        return $this->routes;
    }

    public function current():? string
    {
        return $this->currentName;
    }

    public function active(string|array $name, string $class = 'active'): string
    {
        if ($this->currentName === null) {
            return '';
        }

        if (is_array($name) && in_array($this->currentName, $name, true)) {
            return $class;
        } else if($this->currentName === $name) {
            return $class;
        }

        return '';
    }

}