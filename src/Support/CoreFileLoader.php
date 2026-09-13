<?php

declare(strict_types=1);

namespace Naf\Support;

/**
 * Resolves the conventional layout of an application root or an installed plugin.
 *
 * Both the `app/` and the `src/` spelling are accepted for every resource, and
 * views may additionally live in a top-level `views/` directory. Candidates are
 * probed in order and the first existing one wins, so a package is never loaded
 * from two competing locations at once.
 */
final class CoreFileLoader
{
    /** @var list<string> */
    public const array CONFIG_FILES = ['app/config.php', 'src/config.php'];

    /** @var list<string> */
    public const array ROUTE_FILES = ['app/routes.php', 'src/routes.php'];

    /** @var list<string> */
    public const array PLUGIN_FILES = ['app/plugins.php', 'src/plugins.php'];

    /** @var list<string> */
    public const array FUNCTION_FILES = ['app/functions.php', 'src/functions.php'];

    /** @var list<string> */
    public const array VIEW_HELPER_FILES = ['app/view_helpers.php', 'src/view_helpers.php'];

    /**
     * `src/views` keeps priority so installed plugins behave exactly as before.
     *
     * @var list<string>
     */
    public const array VIEW_PATHS = ['src/views', 'views', 'app/views'];

    /** @var list<string> */
    public const array BOOTSTRAP_FILES = ['bootstrap.php'];

    /**
     * Resolve the first existing file below $root
     *
     * @param string|null  $root       Absolute root directory, or null when unknown
     * @param list<string> $candidates Relative candidates, in order of precedence
     *
     * @return string|null Absolute path, or null when no candidate exists
     */
    public static function file(?string $root, array $candidates): ?string
    {
        return self::resolve($root, $candidates, is_file(...));
    }

    /**
     * Resolve the first existing directory below $root
     *
     * @param string|null  $root       Absolute root directory, or null when unknown
     * @param list<string> $candidates Relative candidates, in order of precedence
     *
     * @return string|null Absolute path, or null when no candidate exists
     */
    public static function directory(?string $root, array $candidates): ?string
    {
        return self::resolve($root, $candidates, is_dir(...));
    }

    /**
     * Build a plugin from the conventional layout of its install path
     *
     * Only resources that actually exist are registered, so consumers of
     * getViewPaths(), getConfigPaths() and friends never see dead paths.
     *
     * @param string $package Composer package name
     * @param string $root    Absolute install path of the package
     *
     * @return Plugin The configured, not yet booted plugin
     */
    public static function createPlugin(string $package, string $root): Plugin
    {
        $plugin = new Plugin($package);

        if (null !== $config = self::file($root, self::CONFIG_FILES)) {
            $plugin->addConfigPath($config);
        }

        if (null !== $routes = self::file($root, self::ROUTE_FILES)) {
            $plugin->addRouteFile($routes);
        }

        if (null !== $views = self::directory($root, self::VIEW_PATHS)) {
            $plugin->addViewPath($views);
        }

        if (null !== $functions = self::file($root, self::FUNCTION_FILES)) {
            $plugin->addFunctionFile($functions);
        }

        if (null !== $viewHelpers = self::file($root, self::VIEW_HELPER_FILES)) {
            $plugin->addViewHelperFile($viewHelpers);
        }

        if (null !== $bootstrap = self::file($root, self::BOOTSTRAP_FILES)) {
            $plugin->setBootstrapFile($bootstrap);
        }

        return $plugin;
    }

    /**
     * @param list<string>          $candidates
     * @param callable(string):bool $exists
     */
    private static function resolve(?string $root, array $candidates, callable $exists): ?string
    {
        if ($root === null || trim($root) === '') {
            return null;
        }

        foreach ($candidates as $candidate) {
            $path = rtrim($root, '/\\') . '/' . ltrim($candidate, '/\\');

            if ($exists($path)) {
                return $path;
            }
        }

        return null;
    }

}
