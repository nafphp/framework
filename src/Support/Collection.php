<?php

declare(strict_types=1);

namespace Naf\Support;

class Collection
{

    /** @template T
     *  @var array<string, T>
     */
    protected array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * @param string     $key
     * @param mixed|null $default
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public function add(string $key, mixed $value = null): static
    {
        $this->items[$key] = $value;
        return $this;
    }

    /**
     * @return array
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->items[$key]);
    }
}
