<?php

namespace Tests\Unit;

use Fixtures\Enums\CustomEvent;
use Fixtures\Events\FailingEventListener;
use Fixtures\Events\OrderShipped;
use Fixtures\Events\TestEventListener;
use Naf\Core\EventManager;
use Naf\Exceptions\AbortException;
use Nyholm\Psr7\Response;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\NafTestCase;
use TypeError;

use function Naf\abort;
use function Naf\app;

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

    /**
     * An object stands for both halves: its class is the name, itself the payload.
     */
    public function testAnEventObjectIsItsOwnNameAndPayload()
    {
        $event = new EventManager();
        $seen  = null;

        $event->listen(OrderShipped::class, function (OrderShipped $shipped) use (&$seen) {
            $seen = $shipped;

            return $shipped->order;
        });

        $shipped = new OrderShipped('A-1');

        $this->assertSame(['A-1'], $event->dispatch($shipped));
        $this->assertSame($shipped, $seen, 'the listener was handed something other than the event');
    }

    /**
     * The same object reaches every listener, which is what makes one of them
     * able to change something the next one sees.
     */
    public function testEveryListenerSeesTheSameObject()
    {
        $event   = new EventManager();
        $shipped = new OrderShipped('A-1');

        $event->listen(OrderShipped::class, fn(OrderShipped $e) => $e->lines[] = 'first', priority: 10);
        $event->listen(OrderShipped::class, fn(OrderShipped $e) => $e->lines[] = 'second');

        $event->dispatch($shipped);

        $this->assertSame(['first', 'second'], $shipped->lines);
    }

    /**
     * Names and objects are separate events even where they look alike, and the
     * string form keeps working -- an application moves over one event at a time
     * or not at all.
     */
    public function testNamedEventsAreUntouched()
    {
        $event = new EventManager();

        $event->listen(CustomEvent::TEST_EVENT, fn() => 'by name');
        $event->listen(OrderShipped::class, fn() => 'by class');

        $this->assertSame(['by name'], $event->dispatch(CustomEvent::TEST_EVENT));
        $this->assertSame(['by class'], $event->dispatch(new OrderShipped('A-1')));
        $this->assertSame([], $event->dispatch('Fixtures\\Events\\NobodyListens'));
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

    public function testDispatchResponsePassesEachReplacementToTheNextListener()
    {
        $event = new EventManager();

        $event->listen(CustomEvent::TEST_EVENT, fn (Response $r) => $r->withHeader('Set-Cookie', 'lang=en'));
        $event->listen(CustomEvent::TEST_EVENT, fn (Response $r) => null);
        $event->listen(CustomEvent::TEST_EVENT, fn (Response $r) => $r->withHeader('X-Frame-Options', 'DENY'));

        $response = $event->dispatchResponse(CustomEvent::TEST_EVENT, new Response(200));

        // Both additions survive: the second listener built on the first one's response.
        $this->assertSame('lang=en', $response->getHeaderLine('Set-Cookie'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testDispatchResponseKeepsTheResponseWithoutReplacements()
    {
        $event    = new EventManager();
        $original = new Response(204);

        $event->listen(CustomEvent::TEST_EVENT, fn () => 'not a response');

        $this->assertSame($original, $event->dispatchResponse(CustomEvent::TEST_EVENT, $original));
        $this->assertSame($original, (new EventManager())->dispatchResponse(CustomEvent::TEST_EVENT, $original));
    }


    /**
     * The guarantee isolation is for: a broken listener is reported, and the
     * listeners after it still run and still answer, in their usual order.
     */
    public function testIsolatedDispatchKeepsRunningAfterAFailingListener()
    {
        $event     = new EventManager();
        $exception = new RuntimeException('first listener failed');
        $line      = __LINE__ + 1;
        $event->listen(CustomEvent::TEST_EVENT, fn () => throw $exception, priority: 10);
        $event->listen(CustomEvent::TEST_EVENT, fn () => 'second');
        $event->listen(CustomEvent::TEST_EVENT, fn () => 'third', priority: -10);

        $logged = $this->captureLoggedErrors(fn () => $this->assertSame(
            ['second', 'third'],
            $event->isolated()->dispatch(CustomEvent::TEST_EVENT),
        ));

        $this->assertCount(1, $logged);
        $this->assertSame('Event listener failed', $logged[0]['message']);
        $this->assertSame(CustomEvent::TEST_EVENT, $logged[0]['context']['event']);
        $this->assertSame('Closure@' . __FILE__ . ':' . $line, $logged[0]['context']['listener']);
        $this->assertSame($exception, $logged[0]['context']['exception']);
    }

    public function testIsolatedDispatchHandsPayloadAndObjectEventsOnAsDispatchDoes()
    {
        $event   = new EventManager();
        $shipped = new OrderShipped('A-1');
        $event->listen(OrderShipped::class, fn (OrderShipped $e) => $e->order);
        $event->listen(CustomEvent::TEST_EVENT, fn (string $file, int $records) => "$file:$records");

        $this->assertSame(['A-1'], $event->isolated()->dispatch($shipped));
        $this->assertSame(['catalog.csv:3'], $event->isolated()->dispatch(CustomEvent::TEST_EVENT, 'catalog.csv', 3));
        $this->assertSame([], $event->isolated()->dispatch('Fixtures\\Events\\NobodyListens'));
    }

    /**
     * isolated() is a view on the same manager, not a copy: listeners added
     * after it was called still receive the event, and the manager's own
     * dispatch() is not switched to isolation by it.
     */
    public function testIsolatedDispatcherUsesTheManagersCurrentListeners()
    {
        $event    = new EventManager();
        $isolated = $event->isolated();
        $event->listen(CustomEvent::TEST_EVENT, fn () => 'added later');

        $this->assertSame(['added later'], $isolated->dispatch(CustomEvent::TEST_EVENT));

        $event->listen(CustomEvent::TEST_EVENT, fn () => throw new RuntimeException('not isolated here'), priority: 10);
        $this->expectException(RuntimeException::class);
        $event->dispatch(CustomEvent::TEST_EVENT);
    }

    /**
     * Errors are isolated as well, and a class listener is named by class and
     * method -- the container builds it inside the isolated call.
     */
    public function testIsolatedDispatchNamesClassListenersAndCatchesErrors()
    {
        $event = new EventManager();
        $event->listen(CustomEvent::TEST_EVENT, [FailingEventListener::class, 'handle'], priority: 10);
        $event->listen(CustomEvent::TEST_EVENT, fn (int $records) => $records, priority: 5);
        $event->listen(CustomEvent::TEST_EVENT, [TestEventListener::class, 'handle']);

        $logged = $this->captureLoggedErrors(fn () => $this->assertSame(
            ['test response from class'],
            $event->isolated()->dispatch(CustomEvent::TEST_EVENT, 'not a number'),
        ));

        $this->assertCount(2, $logged);
        $this->assertSame(FailingEventListener::class . '::handle', $logged[0]['context']['listener']);
        $this->assertInstanceOf(RuntimeException::class, $logged[0]['context']['exception']);
        $this->assertInstanceOf(TypeError::class, $logged[1]['context']['exception']);
    }

    /**
     * Documented on purpose: isolation also swallows an abort(). That is why
     * guards must stay on dispatch(), which the next test pins down.
     */
    public function testIsolatedDispatchAlsoIsolatesAnAbort()
    {
        $event = new EventManager();
        $event->listen(CustomEvent::TEST_EVENT, fn () => abort(400, 'stop'));

        $logged = $this->captureLoggedErrors(fn () => $this->assertSame([], $event->isolated()->dispatch(CustomEvent::TEST_EVENT)));

        $this->assertInstanceOf(AbortException::class, $logged[0]['context']['exception']);
    }

    /**
     * dispatch() is untouched: the first exception still stops the dispatch
     * and reaches the caller, which is what guards such as a CSRF check rely on.
     */
    public function testDispatchStillStopsAtTheFirstException()
    {
        $event = new EventManager();
        $ran   = [];
        $event->listen(CustomEvent::TEST_EVENT, function () use (&$ran) {
            $ran[] = 'guard';
            abort(400, 'CSRF token invalid.');
        }, priority: 10);
        $event->listen(CustomEvent::TEST_EVENT, function () use (&$ran) {
            $ran[] = 'after';
        });

        try {
            $event->dispatch(CustomEvent::TEST_EVENT);
            $this->fail('dispatch() swallowed the exception of a listener');
        } catch (AbortException) {
            $this->assertSame(['guard'], $ran);
        }
    }

    /**
     * A logger that fails itself must not make the isolated dispatch fail;
     * PHP's error log takes the report instead.
     */
    public function testIsolatedDispatchSurvivesAFailingLogger()
    {
        $event = new EventManager();
        $event->listen(CustomEvent::TEST_EVENT, fn () => throw new RuntimeException('listener failed'), priority: 10);
        $event->listen(CustomEvent::TEST_EVENT, fn () => 'still runs');

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new RuntimeException('logger failed'));

        $errorLog         = tempnam(sys_get_temp_dir(), 'naf-event-log');
        $previousErrorLog = ini_set('error_log', $errorLog);

        try {
            $result = $this->withLogger($logger, fn () => $event->isolated()->dispatch(CustomEvent::TEST_EVENT));
        } finally {
            ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);
        }

        $this->assertSame(['still runs'], $result);
        $this->assertStringContainsString(
            'event listener Closure@' . __FILE__,
            (string) file_get_contents($errorLog),
        );
        $this->assertStringContainsString('RuntimeException: listener failed', (string) file_get_contents($errorLog));
        unlink($errorLog);
    }

    /**
     * @return list<array{message: string, context: array<string, mixed>}>
     */
    private function captureLoggedErrors(callable $callback): array
    {
        $logged = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []) use (&$logged) {
            $logged[] = ['message' => $message, 'context' => $context];
        });

        $this->withLogger($logger, $callback);

        return $logged;
    }

    private function withLogger(LoggerInterface $logger, callable $callback): mixed
    {
        $container = app()->container();
        $previous  = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
        $container->set(LoggerInterface::class, $logger);

        try {
            return $callback();
        } finally {
            if ($previous instanceof LoggerInterface) {
                $container->set(LoggerInterface::class, $previous);
            }
        }
    }
}
