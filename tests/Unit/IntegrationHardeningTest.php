<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Core\Container;
use Naf\Core\ErrorHandler;
use Naf\Core\EventManager;
use Naf\Core\ResponseEmitter;
use Naf\Decorators\AutoResolvingContainer;
use Naf\Exceptions\ContainerException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\StreamInterface;
use ReflectionMethod;
use RuntimeException;
use Tests\NafTestCase;

final class IntegrationHardeningTest extends NafTestCase
{
    public function testNullFactoriesStayRegisteredUntilReset(): void
    {
        foreach ([new Container(), new AutoResolvingContainer(new Container())] as $container) {
            $calls = 0;
            $container->set('optional', static function () use (&$calls) {
                ++$calls;

                return null;
            });
            $this->assertTrue($container->has('optional'));
            $this->assertNull($container->get('optional'));
            $this->assertTrue($container->has('optional'));
            $this->assertNull($container->get('optional'));
            $this->assertSame(1, $calls);
            $container->reset('optional');
            $this->assertFalse($container->has('optional'));
        }
    }

    public function testFactoryFailureRetainsCauseAndCanBeRetried(): void
    {
        $container = new Container();
        $cause     = new RuntimeException('configuration missing');
        $container->set('required', static fn() => throw $cause);
        for ($i = 0; $i < 2; ++$i) {
            try {
                $container->get('required');
                $this->fail('Expected failure');
            } catch (ContainerException $error) {
                $this->assertSame($cause, $error->getPrevious());
            }
            $this->assertTrue($container->has('required'));
        }
    }

    public function testObjectListenerUsesItsInjectedState(): void
    {
        $listener = new class {
            public array $seen = [];

            public function handle(string $value): int
            {
                $this->seen[] = $value;

                return count($this->seen);
            }
        };
        $events = new EventManager();
        $events->listen('changed', [$listener, 'handle']);
        $this->assertSame([1], $events->dispatch('changed', 'one'));
        $this->assertSame([2], $events->dispatch('changed', 'two'));
        $this->assertSame(['one', 'two'], $listener->seen);
    }

    public function testOnlyDevEnablesDetailedErrors(): void
    {
        $previous = $_ENV['APP_ENV'] ?? null;

        try {
            $method = new ReflectionMethod(ErrorHandler::class, 'shouldRenderDetailedView');
            foreach (
                [
                    'dev'        => true,
                    'prod'       => false,
                    'test'       => false,
                    'production' => false,
                    'staging'    => false,
                    ''           => false,
                ] as $env => $expected
            ) {
                $_ENV['APP_ENV'] = $env;
                $this->assertSame($expected, $method->invoke(null), $env);
            }
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previous;
            }
        }
    }

    public function testRealOutputGuardAcceptsNullableViewValues(): void
    {
        $app = \Naf\app();
        (new ReflectionMethod($app, 'loadGuards'))->invoke($app);
        $this->assertSame('', \Naf\guard()->safeOutput(null));
        $this->assertSame(['', '&lt;b&gt;'], \Naf\guard()->safeOutput([null, '<b>']));
        $this->assertSame("\u{fffd}", \Naf\guard()->safeOutput("\xff"));
    }

    public function testEmitterReadsBoundedChunksWithoutStringConversion(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects($this->never())->method('__toString');
        $stream->method('isSeekable')->willReturn(true);
        $stream->expects($this->once())->method('rewind');
        $stream->method('eof')->willReturnOnConsecutiveCalls(false, false, true);
        $stream
            ->expects($this->exactly(2))
            ->method('read')
            ->with(8192)
            ->willReturnOnConsecutiveCalls('first', 'second');
        ob_start();
        (new ReflectionMethod(ResponseEmitter::class, 'writeBody'))->invoke(
            null,
            new Response(200, [], $stream),
        );
        $this->assertSame('firstsecond', ob_get_clean());
    }
}
