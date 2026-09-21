<?php

namespace Tests\Unit;

use Fixtures\Enums\CustomEvent;
use Fixtures\Events\TestEventListener;
use Naf\Core\EventManager;
use Nyholm\Psr7\Response;
use Tests\NafTestCase;

class EventTest extends NafTestCase
{

    public function testEventCallable()
    {
        $event = new EventManager();
        $event->listen(CustomEvent::TEST_EVENT, function () { return 'test'; });
        $this->assertSame([0 => 'test'], $event->dispatch(CustomEvent::TEST_EVENT));
    }

    public function testEventClassMethod()
    {
        $event = new EventManager();
        $event->listen(CustomEvent::TEST_EVENT, [TestEventListener::class, 'handle']);
        $this->assertSame([0 => 'test response from class'], $event->dispatch(CustomEvent::TEST_EVENT));
    }

    public function testEventPriorityOrder()
    {
        $event = new EventManager();

        $event->listen(CustomEvent::TEST_EVENT, fn () => 'low priority', priority: -10);
        $event->listen(CustomEvent::TEST_EVENT, fn () => 'default priority'); // 0
        $event->listen(CustomEvent::TEST_EVENT, fn () => 'high priority', priority: 50);

        $responses = $event->dispatch(CustomEvent::TEST_EVENT);

        $this->assertSame(
            ['high priority', 'default priority', 'low priority'],
            $responses
        );
    }

    /**
     * The regression the deferred sort could introduce, and the reason it is a
     * flag rather than a one-off: a listener registered after an event has
     * already been dispatched has to take its place in the order, not the end
     * of the queue.
     */
    public function testAListenerAddedAfterADispatchStillTakesItsPlace()
    {
        $event = new EventManager();

        $event->listen(CustomEvent::TEST_EVENT, fn () => 'first');
        $this->assertSame(['first'], $event->dispatch(CustomEvent::TEST_EVENT));

        $event->listen(CustomEvent::TEST_EVENT, fn () => 'urgent', priority: 100);

        $this->assertSame(
            ['urgent', 'first'],
            $event->dispatch(CustomEvent::TEST_EVENT),
            'a listener registered after the first dispatch was never sorted in'
        );
    }

    /**
     * Listeners that share a priority run in the order they were registered.
     *
     * Nobody declares this and several things rely on it, which is what makes it
     * worth a test: PHP's sort has been stable since 8.0, and the deferred sort
     * must not be the thing that quietly changes it.
     */
    public function testListenersOfEqualPriorityKeepTheOrderTheyWereAddedIn()
    {
        $event = new EventManager();

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $event->listen(CustomEvent::TEST_EVENT, fn () => $name);
        }

        $this->assertSame(['a', 'b', 'c', 'd'], $event->dispatch(CustomEvent::TEST_EVENT));
    }

    /** Sorting once and dispatching twice is still two identical answers. */
    public function testRepeatedDispatchesAnswerTheSame()
    {
        $event = new EventManager();

        $event->listen(CustomEvent::TEST_EVENT, fn () => 'low', priority: -5);
        $event->listen(CustomEvent::TEST_EVENT, fn () => 'high', priority: 5);

        $first = $event->dispatch(CustomEvent::TEST_EVENT);

        $this->assertSame($first, $event->dispatch(CustomEvent::TEST_EVENT));
        $this->assertSame(['high', 'low'], $first);
    }

    public function testDispatchForResponseReturnsNullWithoutListeners()
    {
        $event = new EventManager();

        $this->assertNull($event->dispatchForResponse(CustomEvent::TEST_EVENT));
    }

    public function testDispatchForResponseIgnoresNonResponseReturnValues()
    {
        $event = new EventManager();
        $response = new Response(418);

        $event->listen(CustomEvent::TEST_EVENT, fn () => $response);
        $event->listen(CustomEvent::TEST_EVENT, fn () => null);
        $event->listen(CustomEvent::TEST_EVENT, fn () => 'not a response');

        $this->assertSame($response, $event->dispatchForResponse(CustomEvent::TEST_EVENT));
    }

    public function testDispatchForResponseReturnsTheLastResponse()
    {
        $event = new EventManager();
        $first = new Response(418);
        $last  = new Response(503);

        $event->listen(CustomEvent::TEST_EVENT, fn () => $first, priority: 10);
        $event->listen(CustomEvent::TEST_EVENT, fn () => $last, priority: 0);

        $this->assertSame($last, $event->dispatchForResponse(CustomEvent::TEST_EVENT));
    }

}