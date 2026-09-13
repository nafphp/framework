<?php
declare(strict_types=1);

namespace Naf\Core;

/**
 * Configuration management class that handles array-based config with environment variable support
 */
class Config
{

    /**
     * Internal storage for configuration values
     *
     * @var array<string,mixed>
     */
    private array $config = [];

    /**
     * Creates a new Config instance with optional configuration array
     *
     * @param array<string,mixed> $config Initial configuration array
     */
    public function __construct(array $config = [])
    {
        $this->config = $this->resolveEnv($config);
    }

    /**
     * @param string                       $key
     * @param string|array|object|int|null $default
     *
     * @return string|array|object|null
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $result = $this->config[$key] ?? null;
        if (str_contains($key, ':') !== false) {
            $result = $this->resolveNamespace($key);
        }
        return $result ?? $default;
    }

    public function all(): array
    {
        return $this->config;
    }

    /**
     * @param string $namespace
     * @return string|array|object|null
     */
    private function resolveNamespace(string $namespace): mixed
    {
        $parts   = explode(':', $namespace);
        $pointer = $this->config;
        foreach ($parts as $part) {
            $pointer = $pointer[$part] ?? null;
        }
        return $pointer;
    }

    /**
     * Recursively resolves environment variables in configuration values
     * Environment variables should be prefixed with 'ENV:' in config values
     *
     * @param array<string,mixed> $config Configuration array to process
     *
     * @return array<string,mixed> Processed configuration with resolved ENV values
     */
    private function resolveEnv(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $config[$key] = $this->resolveEnv($value);
            } elseif (is_string($value) && str_starts_with($value, 'ENV:')) {
                $envKey = substr($value, 4);
                $config[$key] = $_ENV[$envKey] ?? null;
            }
        }
        return $config;
    }

}