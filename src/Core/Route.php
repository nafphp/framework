<?php

declare(strict_types=1);

namespace Naf\Core;

use InvalidArgumentException;
use LogicException;
use Naf\Exceptions\MethodNotAllowedException;
use Naf\Exceptions\RouteNotFoundException;

use function Naf\event;

class Route
{
    protected array $routes      = [];
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
        if ($this->routes !== [] && ($name === null || $name === '')) {
            throw new LogicException('You can\'t add routes without a name when there is more than one route configured.');
        }

        // Use an explicit key for unnamed routes; PHP 8.5 deprecates null keys.
        $this->routes[$name ?? ''] = [
            'method' => $method,
            'path'   => $path,
            'action' => $action,
        ];

        return $this;
    }

    /**
     * Removes a named route.
     *
     * Registering a route under an existing name replaces it, so replacing is
     * already possible; removing is what was missing. The name is the identity,
     * and an unknown name is simply false rather than an error.
     *
     * @param string $name
     *
     * @return bool True when a route was removed
     */
    public function remove(string $name): bool
    {
        if (!array_key_exists($name, $this->routes)) {
            return false;
        }

        unset($this->routes[$name]);

        if ($this->currentName === $name) {
            $this->currentName = null;
        }

        return true;
    }

    /**
     * Finds a route matching the given URI and method.
     *
     * @param string $uri
     * @param string $method
     *
     * @return array|null
     * @throws RouteNotFoundException
     * @throws MethodNotAllowedException
     */
    public function find(string $uri, string $method): ?array
    {
        $this->currentName = null;
        $method            = strtoupper($method);
        event()->dispatch(Event::ROUTE_MATCHING, $uri, $method);
        $allowed  = [];
        $fallback = null;

        foreach ($this->routes as $name => $route) {
            // PHP converts numeric string keys to integers; route names stay strings.
            $name    = (string) $name;
            $pattern = '#^' . self::pattern($route['path']) . '$#';
            if (preg_match($pattern, $uri, $matches)) {
                $allowed[] = $route['method'];
                if ($route['method'] === 'GET') {
                    $allowed[] = 'HEAD';
                }
                array_shift($matches);
                preg_match_all('#\{([^}]+)\}#', $route['path'], $paramNames);
                $params = array_combine($paramNames[1], $matches);
                $match  = ['action' => $route['action'], 'params' => $params, 'name' => $name];
                if ($route['method'] === $method) {
                    $this->currentName = $name;
                    event()->dispatch(Event::ROUTE_MATCHED, $route);

                    return $match;
                }
                if ($method === 'HEAD' && $route['method'] === 'GET' && $fallback === null) {
                    $fallback = [$match, $route];
                }
            }
        }

        if ($fallback !== null) {
            [$match, $route]   = $fallback;
            $this->currentName = $match['name'];
            event()->dispatch(Event::ROUTE_MATCHED, $route);

            return $match;
        }
        if ($allowed !== []) {
            $allowed[] = 'OPTIONS';

            throw new MethodNotAllowedException(array_values(array_unique($allowed)));
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
        if (!array_key_exists($name, $this->routes)) {
            throw new RouteNotFoundException("Route '{$name}' not found.");
        }

        $url = $this->routes[$name]['path'];
        preg_match_all('#\{([^}]+)\}#', $url, $placeholders);
        $replacements = [];

        foreach ($placeholders[1] as $placeholder) {
            if (!array_key_exists($placeholder, $params)) {
                throw new InvalidArgumentException("Missing parameter '{$placeholder}' for route '{$name}'.");
            }
            if (!is_string($params[$placeholder]) && !is_int($params[$placeholder])) {
                throw new InvalidArgumentException("Parameter '{$placeholder}' for route '{$name}' must be a string or integer.");
            }
            $replacements['{' . $placeholder . '}'] = (string) $params[$placeholder];
        }

        return strtr($url, $replacements);
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

    public function current(): ?string
    {
        return $this->currentName;
    }

    /** Clear request-specific matching state while retaining registered routes. */
    public function clearCurrent(): void
    {
        $this->currentName = null;
    }

    public function active(string|array $name, string $class = 'active'): string
    {
        if ($this->currentName === null) {
            return '';
        }

        if (is_array($name) && in_array($this->currentName, $name, true)) {
            return $class;
        } elseif ($this->currentName === $name) {
            return $class;
        }

        return '';
    }
}
