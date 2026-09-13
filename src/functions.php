<?php
declare(strict_types=1);

namespace Naf;

if (!defined('NAF_BASE_PATH')) {
    define('NAF_BASE_PATH', dirname(__DIR__));
}

use Naf\Core\App;
use Naf\Core\Config;
use Naf\Core\ErrorHandler;
use Naf\Core\EventManager;
use Naf\Core\Route;
use Naf\Core\Environment;
use Naf\Exceptions\AbortException;
use Naf\Support\AppHolder;
use Naf\Support\Guard;
use Naf\Support\Plugin;
use Naf\Support\RequestParameter;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

if (getenv('APP_ENV') !== Environment::TEST
    && getenv('APP_ENV') !== Environment::PROD
) {
    ErrorHandler::register();
    ini_set('display_errors', false);
}

/**
 * Get the application instance
 */
function app(): App
{
    return AppHolder::get();
}

/**
 * Get configuration value by key
 *
 * @param string|null $key     Configuration key to retrieve
 * @param mixed       $default Default value if key not found
 *
 * @return mixed Configuration value or entire config if no key provided
 */
function config(?string $key = null, mixed $default = null): mixed
{
    $config = app()->container()->get(Config::class);

    if (empty($key)) {
        return $config->all();
    }

    return $config->get($key, $default);
}

/**
 * Get the route instance or generate URL for a named route
 *
 * @param string|null $name   Route name
 * @param array       $params Route parameters
 *
 * @return Route|string Route instance or generated URL
 */
function route(?string $name = null, array $params = []): Route|string
{
    $route = app()->container()->get(Route::class);

    if (null === $name) {
        return $route;
    }

    return $route->url($name, $params);
}

/**
 * Get a specific plugin, or all when no argument is given
 *
 * @return Plugin|Plugin[]
 */
function plugin(?string $name = null): Plugin|array
{
    if ($name) {
        return app()->getPlugin($name);
    }

    return app()->getPlugins();
}

/**
 * Get current request instance
 */
function request(): ServerRequestInterface|RequestInterface
{
    return app()->container()->get(RequestInterface::class);
}

/**
 * Create a new response
 *
 * @param mixed $content Response content
 * @param int   $status  HTTP status code
 * @param array $headers Response headers
 */
function response(mixed $content = '', int $status = 200, array $headers = []): ResponseInterface
{
    return new Response($status, $headers, $content);
}

/**
 * Get request parameter handler instance
 */
function param(): RequestParameter
{
    return app()->container()->get(RequestParameter::class);
}

/**
 * Create JSON response
 *
 * @param mixed $data    Data to encode as JSON
 * @param int   $status  HTTP status code
 * @param array $headers Response headers
 */
function json(mixed $data, int $status = 200, array $headers = []): ResponseInterface
{
    $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (false === $body) {
        throw new \RuntimeException('Unable to encode data to JSON: ' . json_last_error_msg());
    }

    $stream = Stream::create($body);

    $headers = array_merge([
        'Content-Type' => 'application/json; charset=UTF-8',
    ], $headers);

    return response($stream, $status, $headers);
}

/**
 * Create a redirect response
 *
 * @param string $url    Target URL
 * @param int    $status HTTP status code
 */
function redirect(string $url, int $status = 302): ResponseInterface
{
    return response('', $status, ['Location' => $url]);
}

/**
 * Create a refresh response redirecting to the current path
 */
function refresh(): ResponseInterface
{
    $request = app()->container()->get(RequestInterface::class);
    return redirect($request->getUri()->getPath());
}

/**
 * Abort request with status code and message
 *
 * @param int    $statusCode HTTP status code
 * @param string $message    Error message
 *
 * @throws AbortException
 */
function abort(int $statusCode = 404, string $message = ''): never
{
    throw new AbortException(htmlspecialchars($message), $statusCode);
}


/**
 * Get the current environment
 *
 * @return string
 */
function env(): string
{
    return app()->container()->get(Environment::class);
}

/**
 * Get the event dispatcher instance
 */
function event(): EventManager
{
    return app()->container()->get(EventManager::class);
}

/**
 * Get logger instance
 */
function log(): LoggerInterface
{
    return app()->container()->get(LoggerInterface::class);
}

/**
 * Get the guard instance
 */
function guard(): Guard
{
    return app()->guard();
}
