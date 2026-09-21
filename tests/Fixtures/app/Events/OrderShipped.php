<?php

namespace Fixtures\Events;

/**
 * An event that is its own name.
 *
 * Nothing is required of it -- no interface, no base class, no marker. A class
 * is a name that an IDE can follow and a typo cannot survive; that is the whole
 * of what dispatching an object buys, and asking for more than a class would
 * only make it harder to move an existing event over.
 */
final class OrderShipped
{
    public function __construct(public string $order, public array $lines = [])
    {
    }
}
