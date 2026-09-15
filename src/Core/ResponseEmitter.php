<?php

declare(strict_types=1);

namespace Naf\Core;

use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Naf\event;
use function Naf\log;

/**
 * Writes a PSR-7 response to the client.
 *
 * Two entry points exist on purpose: send() is the normal path and runs the
 * response events, emit() is the raw path used while an error is being
 * reported. Both terminate the request.
 */
final class ResponseEmitter
{
    /**
     * Emit a response and run the RESPONSE_HEADER, RESPONSE_BODY and
     * RESPONSE_END events
     *
     * @param ResponseInterface $response Response to send
     */
    public static function send(ResponseInterface $response): never
    {
        self::clearOutputBuffers();

        if (headers_sent()) {
            self::writeBody($response);
            exit(0);
        }

        // Before the head is written a failing listener can still be reported
        // normally, so this dispatch is allowed to throw.
        $response = event()->dispatchForResponse(Event::RESPONSE_HEADER, $response) ?? $response;

        self::writeHead($response);

        // From here on the response is already on the wire. An escaping
        // exception would reach the global error handler, which would append a
        // second, complete error page to the body the client is receiving.
        self::dispatchQuietly(Event::RESPONSE_BODY, $response);

        self::writeBody($response);

        self::dispatchQuietly(Event::RESPONSE_END, $response);

        exit(0);
    }

    /**
     * Dispatch an event whose listeners must not be able to break the response
     *
     * @param string            $event    Event name (use Event::* constants)
     * @param ResponseInterface $response Response passed to the listeners
     */
    private static function dispatchQuietly(string $event, ResponseInterface $response): void
    {
        try {
            event()->dispatch($event, $response);
        } catch (Throwable $e) {
            log()->error(sprintf('Listener for %s failed: %s', $event, $e->getMessage()));
        }
    }

    /**
     * Emit a response without touching the event system
     *
     * Used while handling a fatal error: the listeners are part of the
     * application that just failed, so running them again risks a second
     * exception on top of the one being reported.
     *
     * @param ResponseInterface $response Response to send
     */
    public static function emit(ResponseInterface $response): never
    {
        self::clearOutputBuffers();

        if (!headers_sent()) {
            self::writeHead($response);
        }

        self::writeBody($response);

        exit(0);
    }

    private static function writeBody(ResponseInterface $response): void
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            echo $chunk;
        }
    }

    private static function writeHead(ResponseInterface $response): void
    {
        header(
            sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase(),
            ),
        );

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }
    }

    /**
     * Discard everything buffered so far
     *
     * Shared with the error handler so that a failure never appends its output
     * to a half-rendered page.
     */
    public static function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
