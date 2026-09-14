<?php

declare(strict_types=1);

namespace Naf\Core;

use Naf\Decorators\AutoResolvingContainer;
use Psr\Http\Message\ResponseInterface;
use function Naf\app;

class EventManager
{
    protected array $listeners = [];

    /**
     * Register a listener for a specific event
     *
     * @param string         $event    Name of the event to listen for (use Event::* constants)
     * @param array|callable $listener Listener callback or array containing class and method
     * @param int            $priority Higher priority = earlier execution (default 0)
     *
     * @return EventManager Returns this EventManager instance for chaining
     */
    public function listen(string $event, array|callable $listener, int $priority = 0): EventManager
    {
        $this->listeners[$event][] = [
            'callback' => $listener,
            'priority' => $priority,
        ];

        usort(
            $this->listeners[$event],
            fn ($a, $b) => $b['priority'] <=> $a['priority']
        );

        return $this;
    }

    /**
     * Dispatch an event to all registered listeners
     *
     * @param string $event Name of the event to dispatch (use Event::* constants)
     * @param mixed         ...$payload Variable number of arguments to pass to the listeners
     *
     * @return array Array of responses from all listeners
     */
    public function dispatch(string $event, mixed ...$payload): array
    {
        $responses = [];

        if (!empty($this->listeners[$event])) {

            foreach ($this->listeners[$event] as $listener) {
                $callback = $listener['callback'];

                if (is_array($callback) && is_string($callback[0]) && !is_callable($callback)) {
                    [$class, $handle] = $callback;
                    $container = app()->container();
                    if ($container instanceof AutoResolvingContainer) {
                        $obj = $container->make($class);
                    } else {
                        $obj = new $class();
                    }

                    $responses[] = $obj->$handle(...$payload);
                } elseif (is_callable($callback)) {
                    $responses[] = $callback(...$payload);
                }
            }
        }

        return $responses;
    }

    /**
     * Dispatch an event and return the last response a listener produced
     *
     * Listeners that return something other than a response are ignored, so a
     * listener registered after the one that answered cannot discard its result
     * by returning null.
     *
     * @param string $event      Name of the event to dispatch (use Event::* constants)
     * @param mixed  ...$payload Variable number of arguments to pass to the listeners
     *
     * @return ResponseInterface|null The last response returned by a listener, or null
     */
    public function dispatchForResponse(string $event, mixed ...$payload): ?ResponseInterface
    {
        $responses = array_filter(
            $this->dispatch($event, ...$payload),
            static fn(mixed $response) => $response instanceof ResponseInterface
        );

        return empty($responses) ? null : end($responses);
    }
}
