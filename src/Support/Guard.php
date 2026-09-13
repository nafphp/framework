<?php
declare(strict_types=1);

namespace Naf\Support;

class Guard
{
    /**
     * @var array<string, callable>
     */
    protected array $guards = [];

    public function register(string $name, callable $callback): self
    {
        $this->guards[$name] = $callback;
        return $this;
    }

    public function unregister(string $name): self
    {
        unset($this->guards[$name]);
        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->guards[$name]);
    }

    public function run(string $name, ...$payload): mixed
    {
        if (!$this->has($name) || !is_callable($this->guards[$name])) {
            throw new \RuntimeException('Guard "' . $name . '" not found.');
        }

        return $this->guards[$name](...$payload);
    }

    public function __call(string $name, array $arguments)
    {
        return $this->run($name, ...$arguments);
    }

}