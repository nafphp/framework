<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Core\App;
use Naf\Core\Container;
use Naf\Core\Route;
use Naf\Support\Stopwatch;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\NafTestCase;

final class StopwatchTest extends NafTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Stopwatch::reset();
    }

    public function testNamedStopwatchStartAndStop(): void
    {
        self::assertFalse(Stopwatch::has('metric'));

        Stopwatch::start('metric');
        self::assertTrue(Stopwatch::has('metric'));

        $elapsed = Stopwatch::stop('metric');
        self::assertFalse(Stopwatch::has('metric'));
        self::assertMatchesRegularExpression('/^\d+\.\d{5}$/', $elapsed);
    }

    public function testAnonymousStopwatchWhenSingleTimerIsActive(): void
    {
        Stopwatch::start();
        $elapsed = Stopwatch::stop();

        self::assertMatchesRegularExpression('/^\d+\.\d{5}$/', $elapsed);
    }

    public function testDuplicateStartThrowsRuntimeException(): void
    {
        Stopwatch::start('dup');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stopwatch already started.');
        Stopwatch::start('dup');
    }

    public function testStoppingUnstartedTimerThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stopwatch not started.');
        Stopwatch::stop('missing');
    }

    public function testApplicationLifecycleUsesStopwatchMetrics(): void
    {
        $app = new App(new Container());
        self::assertTrue(Stopwatch::has('app'));

        $app->container()->set(LoggerInterface::class, new NullLogger());
        $app->container()->get(Route::class)->add('GET', '/ping', static fn() => new Response(200, [], 'pong'), 'ping');

        $response = $app->handle(new ServerRequest('GET', '/ping'));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(Stopwatch::has('app'));

        // Subsequent request on the same App starts and stops the timer again.
        $second = $app->handle(new ServerRequest('GET', '/ping'));
        self::assertSame(200, $second->getStatusCode());
        self::assertFalse(Stopwatch::has('app'));
    }
}
