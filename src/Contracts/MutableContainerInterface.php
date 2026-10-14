<?php

declare(strict_types=1);

namespace Naf\Contracts;

use Psr\Container\ContainerInterface;

/**
 * PSR-11 lookup plus the registration operations required by application boot.
 *
 * @api
 */
interface MutableContainerInterface extends ContainerInterface
{
    public function set(string $id, callable|object $factory): void;

    /** Remove the binding and its cached instance. */
    public function reset(string $id): void;
}
