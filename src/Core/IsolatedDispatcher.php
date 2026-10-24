<?php

declare(strict_types=1);

namespace Naf\Core;

use Closure;

/**
 * Dispatches events with every listener isolated from the others
 *
 * Obtained from EventManager::isolated():
 *
 *     event()->isolated()->dispatch('cms.page.published', $page);
 *     event()->isolated()->dispatch(new OrderShipped('A-1'));
 *
 * Each listener runs on its own: an exception it throws is logged with the
 * event and the listener, and the remaining listeners still run. The results
 * of the listeners that succeeded are returned in execution order; a failed
 * listener contributes none. Names, payloads, event objects, priorities and
 * class listeners work exactly as with EventManager::dispatch().
 *
 * Use it for notifications about something that has already happened --
 * content was saved, a mail was sent -- where one broken listener must not
 * take the others down with it. Never use it for guards or anything that
 * stops a request by throwing: an abort() or an authentication exception
 * would be logged and swallowed instead of reaching the error handler.
 * EventManager::dispatch() keeps propagating exceptions for exactly that reason.
 *
 * Isolation costs nothing while listeners succeed; a try block adds no work
 * to a call that does not throw.
 */
final class IsolatedDispatcher
{
    /**
     * @param Closure(string|object, array): array $dispatch The manager's isolated dispatch loop
     */
    public function __construct(private readonly Closure $dispatch)
    {
    }

    /**
     * Dispatch an event to all listeners, each isolated from the others
     *
     * @param string|object $event      Event name, or an event object standing for both
     * @param mixed         ...$payload Arguments for the listeners; ignored for an object
     *
     * @return array Array of responses from the listeners that did not throw
     */
    public function dispatch(string|object $event, mixed ...$payload): array
    {
        return ($this->dispatch)($event, $payload);
    }
}
