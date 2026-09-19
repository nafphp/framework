<?php

declare(strict_types=1);

namespace Naf\Core;

use Closure;
use Naf\Exceptions\ContainerException;
use Naf\Exceptions\ServiceNotFoundException;
use Psr\Container\ContainerInterface;
use Throwable;

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
        if (!array_key_exists($id, $this->services)) {
            throw new ServiceNotFoundException("Service '$id' not found.");
        }

        if ($this->services[$id] instanceof Closure) {
            try {
                $this->services[$id] = call_user_func($this->services[$id], $this);
            } catch (Throwable $e) {
                throw new ContainerException($e->getMessage(), 0, $e);
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
        return array_key_exists($id, $this->services);
    }

    public function reset(string $id): void
    {
        unset($this->services[$id]);
    }
}
