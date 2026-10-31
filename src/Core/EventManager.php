<?php

declare(strict_types=1);

namespace Naf\Core;

use Closure;
use Naf\Decorators\AutoResolvingContainer;
use Psr\Http\Message\ResponseInterface;
use ReflectionFunction;
use Throwable;

use function Naf\app;
use function Naf\log;

class EventManager
{
    protected array $listeners = [];

    /**
     * Events whose listeners are not in priority order yet.
     *
     * Sorting belongs to dispatching, not to registering. Doing it on every
     * listen() sorts a list that is still being built -- n sorts of a growing
     * list, so registering n listeners for one event costs O(n^2 log n), and at
     * a thousand listeners that is most of a tenth of a second spent putting
     * the same list in order a thousand times.
     *
     * An application with a handful of listeners per event will not notice
     * either way. This is here because events are the extension mechanism, so
     * the number of listeners is somebody else's decision, and a limit nobody
     * chose is the kind that is found late.
     *
     * @var array<string, true>
     */
    protected array $unsorted = [];

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

        $this->unsorted[$event] = true;

        return $this;
    }

    /**
     * Dispatch an event to all registered listeners
     *
     * An object may be dispatched instead of a name, in which case its class is
     * the name and the object itself is the payload:
     *
     *     event()->dispatch(new OrderShipped($order));
     *     event()->listen(OrderShipped::class, fn(OrderShipped $e) => ...);
     *
     * listen() needs nothing for this -- `::class` is a string like any other
     * name -- which is why an application can move to objects one event at a
     * time, and why the string form below keeps working for the ones that have
     * no class and do not need one.
     *
     * What it buys is what a string cannot: a misspelled class is an error where
     * it is written, an IDE can find every listener of an event, renaming one is
     * a refactoring rather than a search, and the payload has a declared shape
     * instead of a docblock describing variadic arguments.
     *
     * @param string|object $event      Event name, or an event object standing for both
     * @param mixed         ...$payload Arguments for the listeners; ignored for an object
     *
     * @return array Array of responses from all listeners
     */
    public function dispatch(string|object $event, mixed ...$payload): array
    {
        if (is_object($event)) {
            $payload = [$event];
            $event   = $event::class;
        }

        $responses = [];

        foreach ($this->sortedListeners($event) as $listener) {
            if ($this->isInvokable($listener['callback'])) {
                $responses[] = $this->invoke($listener['callback'], $payload);
            }
        }

        return $responses;
    }

    /**
     * Dispatch through this manager with every listener isolated
     *
     *     event()->isolated()->dispatch('cms.page.published', $page);
     *
     * Each listener then runs on its own: an exception it throws is logged with
     * the event and the listener, and the remaining listeners still run. See
     * IsolatedDispatcher for when to use it -- never for guards.
     *
     * The returned dispatcher uses this manager's listeners as they are when
     * it dispatches, including listeners registered after isolated() was called.
     */
    public function isolated(): IsolatedDispatcher
    {
        return new IsolatedDispatcher($this->dispatchEachIsolated(...));
    }

    /**
     * The isolated dispatch loop behind isolated()->dispatch()
     *
     * @param string|object $event   Event name, or an event object standing for both
     * @param array         $payload Arguments for the listeners; ignored for an object
     *
     * @return array Array of responses from the listeners that did not throw
     */
    private function dispatchEachIsolated(string|object $event, array $payload): array
    {
        if (is_object($event)) {
            $payload = [$event];
            $event   = $event::class;
        }

        $responses = [];

        foreach ($this->sortedListeners($event) as $listener) {
            if (!$this->isInvokable($listener['callback'])) {
                continue;
            }

            try {
                $responses[] = $this->invoke($listener['callback'], $payload);
            } catch (Throwable $exception) {
                $this->reportListenerFailure($event, $listener['callback'], $exception);
            }
        }

        return $responses;
    }

    /**
     * Dispatch an event whose payload is a response that listeners may replace
     *
     * Each listener receives the response the previous listener returned, so
     * several listeners can each add their own headers. With dispatchForResponse()
     * every listener saw the original response and only the last replacement
     * survived: an application listener adding security headers silently dropped
     * the language cookie naf/i18n had added. A listener that returns anything
     * other than a response leaves the current one in place.
     *
     * @param string            $event    Event name
     * @param ResponseInterface $response The response before any listener ran
     *
     * @return ResponseInterface The response after every listener had its turn
     */
    public function dispatchResponse(string $event, ResponseInterface $response): ResponseInterface
    {
        foreach ($this->sortedListeners($event) as $listener) {
            if (!$this->isInvokable($listener['callback'])) {
                continue;
            }

            $replacement = $this->invoke($listener['callback'], [$response]);

            if ($replacement instanceof ResponseInterface) {
                $response = $replacement;
            }
        }

        return $response;
    }

    /**
     * The listeners of an event, highest priority first
     *
     * @return list<array{callback: array|callable, priority: int}>
     */
    private function sortedListeners(string $event): array
    {
        if (isset($this->unsorted[$event], $this->listeners[$event])) {
            // Stable since PHP 8.0, so listeners that share a priority still run
            // in the order they were registered -- which several of them rely on
            // and none of them declares.
            usort($this->listeners[$event], fn($a, $b) => $b['priority'] <=> $a['priority']);
            unset($this->unsorted[$event]);
        }

        return $this->listeners[$event] ?? [];
    }

    /**
     * Whether a listener is a callable or a [ClassName::class, 'method'] pair
     */
    private function isInvokable(array|callable $callback): bool
    {
        return $this->isClassListener($callback) || is_callable($callback);
    }

    /**
     * A [ClassName::class, 'method'] pair whose object the container still has to build
     */
    private function isClassListener(array|callable $callback): bool
    {
        return is_array($callback) && is_string($callback[0]) && !is_callable($callback);
    }

    /**
     * Call one listener; class listeners are built through the container
     *
     * @param array|callable $callback The registered listener
     * @param array          $payload  Arguments for the listener
     */
    private function invoke(array|callable $callback, array $payload): mixed
    {
        if ($this->isClassListener($callback)) {
            [$class, $handle] = $callback;
            $container        = app()->container();

            if ($container->has($class)) {
                $object = $container->get($class);
            } elseif ($container instanceof AutoResolvingContainer) {
                $object = $container->make($class);
            } else {
                $object = new $class();
            }

            return $object->$handle(...$payload);
        }

        return $callback(...$payload);
    }

    /**
     * Log a listener that failed during an isolated dispatch
     *
     * A logger that fails itself must not turn the isolated dispatch into a
     * failing one, so PHP's error log is the last resort.
     */
    private function reportListenerFailure(string $event, array|callable $callback, Throwable $exception): void
    {
        $listener = 'unknown listener';

        try {
            $listener = $this->describeListener($callback);
            log()->error('Event listener failed', [
                'event'     => $event,
                'listener'  => $listener,
                'exception' => $exception,
            ]);
        } catch (Throwable) {
            error_log(sprintf(
                'NAF: event listener %s for "%s" failed: %s: %s',
                $listener,
                $event,
                $exception::class,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * A readable name for a listener, for log entries
     */
    private function describeListener(array|callable $callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback)) {
            $target = $callback[0] ?? null;
            $method = $callback[1] ?? null;
            $class  = is_object($target) ? $target::class : (is_string($target) ? $target : 'unknown');

            return $class . '::' . (is_string($method) ? $method : 'unknown');
        }

        if ($callback instanceof Closure) {
            $function = new ReflectionFunction($callback);
            $file     = $function->getFileName();

            return $file === false ? 'Closure' : sprintf('Closure@%s:%d', $file, $function->getStartLine());
        }

        return is_object($callback) ? $callback::class . '::__invoke' : 'callable';
    }

    /**
     * Dispatch an event and return the last response a listener produced
     *
     * Listeners that return something other than a response are ignored, so a
     * listener registered after the one that answered cannot discard its result
     * by returning null.
     *
     * @param string|object $event      Event name, or an event object standing for both
     * @param mixed         ...$payload Arguments for the listeners; ignored for an object
     *
     * @return ResponseInterface|null The last response returned by a listener, or null
     */
    public function dispatchForResponse(string|object $event, mixed ...$payload): ?ResponseInterface
    {
        $responses = array_filter(
            $this->dispatch($event, ...$payload),
            static fn(mixed $response) => $response instanceof ResponseInterface,
        );

        return empty($responses) ? null : end($responses);
    }
}
