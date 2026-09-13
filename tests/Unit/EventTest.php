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