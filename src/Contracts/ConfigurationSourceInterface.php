<?php

declare(strict_types=1);

namespace Naf\Contracts;

/**
 * An optional configuration layer loaded before any plugin boots.
 *
 * Sources must not resolve application services: those services may themselves
 * need configuration. Return literal values under colon-separated config keys.
 */
interface ConfigurationSourceInterface
{
    public function name(): string;

    /** @return array<string, mixed> */
    public function load(string $basePath): array;
}
