<?php

declare(strict_types=1);

namespace Naf\Core;

use Composer\InstalledVersions;
use Naf\Support\AppHolder;
use Naf\Support\CoreFileLoader;
use Naf\Support\Guard;
use Naf\Support\Plugin;
use Naf\Support\RequestParameter;
use Naf\Support\Stopwatch;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use function Naf\event;
use function Naf\log;

class App
{
    private ContainerInterface $container;

    /** @var Plugin[] */
    private array $plugins = [];

    /**
     * Initialize the application with a dependency container
     *
     * @param ContainerInterface $container The dependency injection container
     */
    public function __construct(ContainerInterface $container)
    {
        Stopwatch::start('app');
        $this->container = $container;
        AppHolder::set($this);
        $this->boot();
    }

    /**
     * Run the application and handle the HTTP request-response cycle
     */
    public function run(): void
    {
        if (PHP_SAPI === 'cli') return;

        try {
            $request = $this->createServerRequest();
            $this->registerRequest($request);

            $this->container()->get(EventManager::class)->dispatch(Event::REQUEST_START, $request);
            $response = $this->container->get(Dispatcher::class)->forward($request);
        } catch (Throwable $e) {
            $response = $this->handleThrowable($e);
        }

        $this->finalizeResponse($response);

        ResponseEmitter::send($response); // This will abort the request as exit(0) is called
    }

    private function registerRequest(ServerRequestInterface $request): void
    {
        $this->container()->set(RequestInterface::class, $request);
        $this->container()->set(ServerRequestInterface::class, $request);
        $this->container()->set(RequestParameter::class, function(ContainerInterface $container) {
            return new RequestParameter($container->get(ServerRequestInterface::class));
        });
    }

    private function handleThrowable(Throwable $e): ResponseInterface
    {
        try {
            $exceptionResponse = event()->dispatchForResponse(Event::EXCEPTION, $e);

            if ($exceptionResponse !== null) {
                return $exceptionResponse;
            }
        } catch (Throwable $listenerException) {
            log()->error(sprintf(
                'Exception listener failed while handling %s: %s',
                $e::class,
                $listenerException->getMessage()
            ));
        }

        log()->error($e->getMessage());

        $statusCode = ErrorHandler::resolveStatusCode($e);
        return ErrorHandler::renderResponse($e, $statusCode);
    }

    /**
     * Log the request timing and let listeners see the final response
     *
     * Failures here must not replace an already rendered response, so they are
     * logged instead of bubbling out of run().
     *
     * @param ResponseInterface $response The response about to be sent
     */
    private function finalizeResponse(ResponseInterface $response): void
    {
        try {
            log()->info('Request completed in ' . Stopwatch::stop('app') . 's');
            event()->dispatch(Event::RESPONSE_SEND, $response);
        } catch (Throwable $e) {
            log()->error('Response finalisation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the dependency injection container
     *
     * @return Container The container instance
     */
    public function container(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Get the guard service for security checks
     *
     * @return Guard The guard service instance
     */
    public function guard(): Guard
    {
        return $this->container->get(Guard::class);
    }

    /**
     * Returns an array of all loaded plugins
     *
     * @return Plugin[]
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function hasPlugin(string $name): bool
    {
        [$package, $constraint] = Plugin::splitRequirement($name);

        if (!isset($this->plugins[$package])) {
            return false;
        }

        return $this->plugins[$package]->satisfiesVersionConstraint($constraint);
    }

    public function getPlugin(string $name): Plugin
    {
        [$package] = Plugin::splitRequirement($name);

        if (!$this->hasPlugin($name)) {
            throw new \InvalidArgumentException('Plugin not found: ' . $name);
        }

        return $this->plugins[$package];
    }

    /**
     * @param string $resource
     *
     * @return array
     */
    public function collectPluginResources(string $resource): array
    {
        if (!in_array($resource, ['configPaths', 'viewPaths', 'routeFiles', 'functionsFiles', 'viewHelpersFiles'])) {
            throw new \InvalidArgumentException('Invalid plugin property type: ' . $resource);
        }

        $result = [];
        $getter = 'get' . ucfirst($resource);

        foreach ($this->getPlugins() as $plugin) {

            $resp = $plugin->$getter();

            if (is_array($resp)) {
                $result = array_merge($result, $resp);
                continue;
            }

            $result[] = $resp;

        }

        return $result;
    }

    /**
     * Get the application base path
     *
     * @return string|null The base path or null if not defined
     */
    public function getBasePath():? string
    {
        if (!defined('\BASE_PATH')) {
            return null;
        }

        return \BASE_PATH;
    }

    /**
     * Get the framework core base path
     *
     * @return string|null The core base path or null if not defined
     */
    private function getCoreBasePath():? string
    {
        if (!defined('\NAF_BASE_PATH')) {
            return null;
        }

        return \NAF_BASE_PATH;
    }

    /**
     * Bootstrap the application by loading environment, services, plugins, routes and guards
     */
    private function boot(): void
    {
        $envFile = file_exists($this->getBasePath() . '/.env.local')
            ? $this->getBasePath() . '/.env.local'
            : $this->getBasePath() . '/.env';

        $this->loadEnv($envFile);
        $this->loadServices();
        $this->loadPlugins();
        $this->loadRoutes();
        if (PHP_SAPI !== 'cli') $this->loadGuards();
    }

    /**
     * Load environment variables from a file
     *
     * @param string $path Path to the environment file
     */
    private function loadEnv(string $path = '/.env'): void
    {
        if (!file_exists($path)) return;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {

            if (str_starts_with(trim($line), '#')) continue;

            [$key, $value] = explode('=', $line, 2);

            $key   = trim($key);
            $value = trim($value);

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }

        }
    }

    /**
     * Register core services in the container
     */
    private function loadServices(): void
    {
        $appConfigFile = CoreFileLoader::file($this->getBasePath(), CoreFileLoader::CONFIG_FILES);
        $coreDir       = $this->getCoreBasePath() . '/src';

        $this->container->set(Environment::class, fn() => $_ENV['APP_ENV'] ?? getenv('APP_ENV'));

        $this->container->set(Guard::class, fn() => new Guard());

        $this->container->set(Route::class, fn() => new Route());

        $this->container->set(Dispatcher::class, fn($container) => new Dispatcher($container->get(Route::class)));

        $this->container->set(EventManager::class, fn() => new EventManager());

        $this->container->set(Config::class, function() use ($appConfigFile, $coreDir) {

            $appConfig  = self::requireConfig($appConfigFile);
            $coreConfig = self::requireConfig($coreDir . '/config.php');

            $pluginConfig = [];

            $configPaths = $this->collectPluginResources('configPaths');

            foreach ($configPaths as $file) {
                $pluginConfig = array_replace_recursive($pluginConfig, self::requireConfig($file));
            }

            $merged = array_replace_recursive($coreConfig, $pluginConfig, $appConfig);

            return new Config($merged);

        });

        $this->container->set(LoggerInterface::class, function() {

            $logFile   = $this->getBasePath() . '/logs/app.log';
            $directory = dirname($logFile);

            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            if (!file_exists($logFile)) {
                touch($logFile);
                chmod($logFile, 0664);
            }

            return new Log($logFile);

        });

    }

    /**
     * Load application routes from the route file
     */
    private function loadRoutes(): void
    {
        $routes = CoreFileLoader::file($this->getBasePath(), CoreFileLoader::ROUTE_FILES);
        if ($routes !== null) require_once $routes;
    }

    /**
     * Read a config file that is expected to return an array
     *
     * A file without a `return` statement evaluates to int(1), which would
     * blow up the merge further down. Treat anything that is not an array as
     * an empty config instead.
     *
     * @param string|null $file Absolute path, or null when the file is unknown
     *
     * @return array The returned config, or an empty array
     */
    private static function requireConfig(?string $file): array
    {
        if ($file === null || !is_file($file)) {
            return [];
        }

        $config = require $file;

        return is_array($config) ? $config : [];
    }

    /**
     * Load and initialize installed plugins
     */
    private function loadPlugins(): void
    {
        // Try to load configuration order from userspace
        $orderedPackages = [];
        $pluginConfigPath = CoreFileLoader::file($this->getBasePath(), CoreFileLoader::PLUGIN_FILES);

        if ($pluginConfigPath !== null) {
            $configured = require $pluginConfigPath;
            $orderedPackages = is_array($configured) ? $configured : [];
        }

        $allPackages = array_unique(InstalledVersions::getInstalledPackagesByType('naf-plugin'));
        $ordered = array_filter($orderedPackages, fn($name) => in_array($name, $allPackages));
        $remaining = array_diff($allPackages, $ordered);

        $finalOrder = array_merge($ordered, $remaining);

        // Register every plugin before booting any of them. Booting runs
        // userland code that may call config(), and the Config service caches
        // itself on first access: if the registry were still filling up at
        // that point, the config of every plugin after it would be lost.
        foreach ($finalOrder as $package) {

            $path = InstalledVersions::getInstallPath($package);

            if (!$path) continue;

            $plugin = CoreFileLoader::createPlugin($package, $path);
            $plugin->setVersion($this->resolvePluginVersion($package));

            $this->plugins[$package] = $plugin;

        }

        $this->bootPlugins();

    }

    /**
     * Boot every registered plugin, in registration order
     *
     * Split from the registration loop so that userland code running during
     * boot always sees the complete plugin registry.
     */
    private function bootPlugins(): void
    {
        foreach ($this->plugins as $plugin) {
            $plugin->boot();
        }
    }

    private function resolvePluginVersion(string $package): ?string
    {
        try {
            $version = InstalledVersions::getPrettyVersion($package) ?? InstalledVersions::getVersion($package);
        } catch (\OutOfBoundsException) {
            return null;
        }

        return $version;
    }

    /**
     * Register security guard rules
     */
    private function loadGuards(): void
    {
        $config = $this->container->get(Config::class);

        $this->guard()->register('safePath', function ($path) {

            if (
                $path === '' ||
                str_contains($path, '..') ||
                str_starts_with($path, '/') ||
                str_contains($path, '://') ||
                !preg_match('/^[A-Za-z0-9_\/.-]+$/', $path)
            ) {
                throw new \InvalidArgumentException('Insecure path detected! Navigation outside of application root is not allowed.');
            }

            return $path;

        });

        $this->guard()->register('safeOutput', function ($value) {

            if (is_array($value)) {
                return array_map(fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $value);
            }

            return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        });

        $this->guard()->register('ipBlacklist', function (string $ip, array $list = []) use ($config) {

            if (empty($list)) {
                $list = $config->get('guard:ipBlacklist');
            }

            if (in_array($ip, $list, true)) {
                throw new \InvalidArgumentException('IP address is blacklisted!');
            }

            return true;

        });

        $this->guard()->register('userAgentBlacklist', function (string $userAgent, array $list = []) use ($config) {

            if (empty($list)) {
                $list = $config->get('guard:userAgentBlacklist');
            }

            if (in_array($userAgent, $list, true)) {
                throw new \InvalidArgumentException('UserAgent is blacklisted!');
            }

            return true;

        });

    }

    /**
     * Create a PSR-7 server request from globals
     *
     * @return ServerRequestInterface The created server request
     */
    private function createServerRequest(): ServerRequestInterface
    {
        $psr17Factory = new Psr17Factory();
        $creator = new ServerRequestCreator(
            $psr17Factory, // ServerRequestFactory
            $psr17Factory, // UriFactory
            $psr17Factory, // UploadedFileFactory
            $psr17Factory  // StreamFactory
        );
        return $creator->fromGlobals();
    }

}
