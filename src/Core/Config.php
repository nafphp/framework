<?php

declare(strict_types=1);

namespace Naf\Core;

use InvalidArgumentException;

/** Array configuration with environment references and named override layers. */
class Config
{
    private array $config;
    private array $raw;
    private array $layers     = [];
    private ?array $effective = null;

    public function __construct(array $config = [])
    {
        $this->raw    = $config;
        $this->config = $this->resolveEnv($config);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return self::at($this->all(), $key) ?? $default;
    }

    public function all(): array
    {
        if ($this->effective !== null) {
            return $this->effective;
        }
        $values = $this->config;
        foreach ($this->layers as $layer) {
            foreach ($layer as $key => $value) {
                $pointer = &$values;
                $parts   = explode(':', $key);
                foreach ($parts as $part) {
                    if (!is_array($pointer)) {
                        $pointer = [];
                    }
                    $pointer = &$pointer[$part];
                }
                $pointer = $value;
                unset($pointer);
            }
        }

        return $this->effective = $values;
    }

    /** Replace a whole named layer; an empty layer removes its overrides. */
    public function overlay(string $source, array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '' || in_array('', explode(':', $key), true)) {
                throw new InvalidArgumentException('Configuration override keys must be non-empty colon-separated paths.');
            }
        }
        $this->layers[$source] = $values;
        $this->effective       = null;
    }

    /** Literal values in one named layer, for source-specific persistence. */
    public function overrides(string $source): array
    {
        return $this->layers[$source] ?? [];
    }

    /** Resolved server configuration, without any runtime overrides. */
    public function base(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->config : (self::at($this->config, $key) ?? $default);
    }

    /** Raw server configuration, retaining ENV references for provenance. */
    public function raw(?string $key = null): mixed
    {
        return $key === null ? $this->raw : self::at($this->raw, $key);
    }

    public function source(string $key): string
    {
        foreach (array_reverse($this->layers, true) as $source => $values) {
            foreach ($values as $path => $value) {
                if ($key === $path || str_starts_with($key, $path . ':')) {
                    return $source;
                }
            }
        }
        $raw = $this->raw($key);

        return is_string($raw) && str_starts_with($raw, 'ENV:')
            ? 'environment' : 'configuration';
    }

    private static function at(array $values, string $key): mixed
    {
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }
        $pointer = $values;
        foreach (explode(':', $key) as $part) {
            if (!is_array($pointer) || !array_key_exists($part, $pointer)) {
                return null;
            }
            $pointer = $pointer[$part];
        }

        return $pointer;
    }

    private function resolveEnv(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $config[$key] = $this->resolveEnv($value);
            } elseif (is_string($value) && str_starts_with($value, 'ENV:')) {
                $envKey       = substr($value, 4);
                $config[$key] = $_ENV[$envKey] ?? (getenv($envKey) === false ? null : getenv($envKey));
            }
        }

        return $config;
    }
}
