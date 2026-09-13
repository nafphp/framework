<?php

declare(strict_types=1);

namespace Naf\Core;

use Closure;
use Naf\Exceptions\ContainerException;
use Naf\Exceptions\ServiceNotFoundException;
use Psr\Container\ContainerInterface;

class Container implements ContainerInterface
{
    private array $services = [];

    /**
     * @template T
     * @param class-string<T> $id
     *
     * @return T|string
     *
     * @throws ServiceNotFoundException
     * @throws ContainerException
     */
    public function get(string $id)
    {
        if (!isset($this->services[$id])) {
            throw new ServiceNotFoundException("Service '$id' not found.");
        }

        if ($this->services[$id] instanceof Closure) {
            try {
                $this->services[$id] = call_user_func($this->services[$id], $this);
            } catch (\Throwable $e) {
                throw new ContainerException($e->getMessage());
            }
        }

        return $this->services[$id];
    }

    public function set(string $id, callable|object $factory): void
    {
        $this->services[$id] = $factory;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    public function reset(string $id): void
    {
        unset($this->services[$id]);
    }

}