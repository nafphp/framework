<?php

declare(strict_types=1);

namespace Naf\Support;

class Plugin
{
    protected string $name;
    protected array $configPaths = [];
    protected array $viewPaths   = [];
    protected array $routeFiles  = [];
    protected array $functionsFiles   = [];
    protected array $viewHelpersFiles = [];
    protected ?string $bootstrap = null;
    private bool $booted = false;
    private ?string $version = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function addConfigPath(string $path): void
    {
        $this->configPaths[] = $path;
    }

    public function addViewPath(string $path): void
    {
        $this->viewPaths[] = $path;
    }

    public function addRouteFile(string $path): void
    {
        $this->routeFiles[] = $path;
    }

    public function addFunctionFile(string $path): void
    {
        $this->functionsFiles[] = $path;
    }

    public function addViewHelperFile(string $path): void
    {
        $this->viewHelpersFiles[] = $path;
    }

    public function setBootstrapFile(string $path): void
    {
        $this->bootstrap = $path;
    }

    /**
     * Whether boot() has already run for this plugin
     *
     * Plugins are registered before any of them boots, so during the boot
     * phase the registry can contain plugins that have not run yet.
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->configPaths as $configPath) {
            if (!file_exists($configPath)) continue;
            require_once $configPath;
        }

        foreach ($this->routeFiles as $routeFile) {
            if (!file_exists($routeFile)) continue;
            require_once $routeFile;
        }

        foreach ($this->functionsFiles as $functionFile) {
            if (!file_exists($functionFile)) continue;
            require_once $functionFile;
        }

        foreach ($this->viewHelpersFiles as $viewHelperFile) {
            if (!file_exists($viewHelperFile)) continue;
            require_once $viewHelperFile;
        }

        if ($this->bootstrap !== null && file_exists($this->bootstrap)) {
            require_once $this->bootstrap;
        }

        $this->booted = true;
    }

    public function getConfigPaths(): array
    {
        return $this->configPaths;
    }

    public function getViewPaths(): array
    {
        return $this->viewPaths;
    }

    public function getRouteFiles(): array
    {
        return $this->routeFiles;
    }

    public function getFunctionsFiles(): array
    {
        return $this->functionsFiles;
    }

    public function getViewHelpersFiles(): array
    {
        return $this->viewHelpersFiles;
    }

    public function getBootstrapFile(): ?string
    {
        return $this->bootstrap;
    }

    public function setVersion(?string $version): void
    {
        $this->version = self::normalizeVersion($version);
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function satisfiesVersionConstraint(?string $constraint): bool
    {
        if ($constraint === null) {
            return true;
        }

        if ($this->version === null) {
            return false;
        }

        [$operator, $targetVersion] = self::parseVersionConstraint($constraint);

        if ($targetVersion === null || $targetVersion === '') {
            return false;
        }

        return version_compare($this->version, $targetVersion, $operator);
    }

    public static function splitRequirement(string $requirement): array
    {
        if (!str_contains($requirement, ':')) {
            return [$requirement, null];
        }

        [$package, $constraint] = explode(':', $requirement, 2);
        $package = trim($package);
        $constraint = trim($constraint);

        return [
            $package,
            $constraint === '' ? null : $constraint,
        ];
    }

    private static function parseVersionConstraint(string $constraint): array
    {
        if (preg_match('/^(>=|<=|>|<|=)?\s*(.+)$/', trim($constraint), $matches)) {
            $operator = $matches[1] ?: '==';
            $version = self::normalizeVersion($matches[2]);

            return [$operator, $version];
        }

        return ['==', self::normalizeVersion($constraint)];
    }

    public static function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        return ltrim(trim($version), 'vV');
    }
}
