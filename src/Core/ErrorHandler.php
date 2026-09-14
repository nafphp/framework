<?php

declare(strict_types=1);

namespace Naf\Core;

use ErrorException;
use Psr\Http\Message\ResponseInterface;
use function Naf\simple_view;
use function Naf\response;

class ErrorHandler
{

    private const string DEFAULT_TEMPLATE = __DIR__ . '/../Resources/views/errors/default.phtml';
    private const string SANITIZED_TEMPLATE = __DIR__ . '/../Resources/views/errors/minimal.phtml';
    private static bool $shutdownRegistered = false;


    /**
     * Handles uncaught exceptions by rendering an error view
     *
     * Sanitizes exception details and renders them using the error view template,
     * then sends the response with HTTP 500 status code
     *
     * @param \Throwable $e The uncaught exception to handle
     *
     * @return void
     */
    public static function handleException(\Throwable $e): void
    {
        if (PHP_SAPI === 'cli') {
            self::renderConsole($e);
        }

        $statusCode = self::resolveStatusCode($e);

        ResponseEmitter::emit(self::renderResponse($e, $statusCode));
    }

    /**
     * Report an exception on the console and abort with a failing exit code
     *
     * The message and origin are always shown because the audience is the
     * operator running the command, not a visitor. The stack trace follows the
     * same rule as the HTTP output and stays hidden outside development.
     *
     * @param \Throwable $e The uncaught exception to report
     */
    private static function renderConsole(\Throwable $e): never
    {
        ResponseEmitter::clearOutputBuffers();

        $report = sprintf(
            "%s: %s%sin %s:%d%s",
            $e::class,
            $e->getMessage(),
            PHP_EOL,
            $e->getFile(),
            $e->getLine(),
            PHP_EOL
        );

        if (self::shouldRenderDetailedView()) {
            $report .= PHP_EOL . $e->getTraceAsString() . PHP_EOL;
        }

        fwrite(STDERR, $report);

        exit(1);
    }

    public static function resolveStatusCode(\Throwable $exception): int
    {
        return method_exists($exception, 'getStatusCode')
            ? $exception->getStatusCode()
            : 500;
    }

    public static function register(): void
    {
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'handleShutdown']);
            self::$shutdownRegistered = true;
        }
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if (!isset($error['type'], $error['message'], $error['file'], $error['line'])) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ];

        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        $exception = new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        );

        try {
            self::handleException($exception);
        } catch (\Throwable $shutdownException) {
            self::sendShutdownFallback($shutdownException);
        }
    }

    /**
     * Renders an HTTP response for an exception.
     *
     * The detailed template is only rendered for non-production/test environments.
     *
     * @param \Throwable $exception
     * @param int        $statusCode
     * @param string|null $template
     *
     * @return ResponseInterface
     * @internal Keep this logic tied to the framework error handling contract to avoid leaking sensitive information.
     */
    public static function renderResponse(\Throwable $exception, int $statusCode, ?string $template = null): ResponseInterface
    {
        if (!self::shouldRenderDetailedView()) {
            return response(
                simple_view(
                    self::SANITIZED_TEMPLATE,
                    self::buildSanitizedViewData($statusCode)
                ),
                $statusCode
            );
        }

        $template = $template ?? self::DEFAULT_TEMPLATE;

        return response(
            simple_view(
                $template,
                self::buildViewData($exception, $statusCode)
            ),
            $statusCode
        );
    }

    /**
     * Converts PHP errors to ErrorException instances unless the error was suppressed.
     *
     * @param int    $errno   The error reporting level
     * @param string $errstr  The error message
     * @param string $errfile The file where the error occurred
     * @param int    $errline The line number where the error occurred
     *
     * @return bool False when the error was silenced via @, otherwise never returns because it throws.
     * @throws ErrorException Always throws the error as an exception when not suppressed.
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if ((error_reporting() & $errno) === 0) {
            return false;
        }

        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    private static function shouldRenderDetailedView(): bool
    {
        $environment = self::getEnvironment();

        if ($environment === null) {
            self::logMissingEnvironmentWarning();
            return false;
        }

        return $environment === Environment::DEV;
    }

    private static function getEnvironment(): ?string
    {
        if (isset($_ENV['APP_ENV'])) {
            return $_ENV['APP_ENV'];
        }

        $value = getenv('APP_ENV');
        return $value === false ? null : $value;
    }

    private static function logMissingEnvironmentWarning(): void
    {
        static $hasWarned = false;

        if ($hasWarned) {
            return;
        }

        $hasWarned = true;

        if (function_exists('Naf\\log')) {
            try {
                \Naf\log()->warning('APP_ENV is not set; defaulting to sanitized error output.');
                return;
            } catch (\Throwable) {
            }
        }

        error_log('APP_ENV is not set; defaulting to sanitized error output.');
    }

    private static function buildSanitizedViewData(int $statusCode): array
    {
        return [
            'statusCode' => $statusCode,
        ];
    }

    private static function buildViewData(\Throwable $exception, int $statusCode): array
    {
        return [
            'statusCode'       => $statusCode,
            'message'          => $exception->getMessage(),
            'exceptionFile'    => $exception->getFile(),
            'exceptionLine'    => $exception->getLine(),
            'exceptionSnippet' => self::getCodeSnippet($exception->getFile(), $exception->getLine()),
            'frames'           => self::buildStackFrames($exception->getTrace()),
            'basePath'         => self::resolveBasePath(),
        ];
    }

    private static function buildStackFrames(array $trace): array
    {
        return array_values(array_map(fn($frame) => self::hydrateFrame($frame), $trace));
    }

    private static function hydrateFrame(array $frame): array
    {
        $line = isset($frame['line']) ? (int)$frame['line'] : null;
        $file = $frame['file'] ?? null;

        return [
            'function' => self::formatFrameFunction($frame),
            'file'     => $file,
            'line'     => $line,
            'snippet'  => self::getCodeSnippet($file, $line),
        ];
    }

    private static function formatFrameFunction(array $frame): string
    {
        $function = $frame['function'] ?? null;

        if (isset($frame['class']) && isset($frame['type'])) {
            $name = $frame['class'] . $frame['type'] . ($function ?? '');
        } elseif ($function) {
            $name = $function;
        } else {
            $name = '[internal]';
        }

        return $name;
    }

    private static function getCodeSnippet(?string $file, ?int $line, int $padding = 10): array
    {
        if (empty($file) || $line === null || !is_file($file)) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $totalLines = count($lines);
        $start = max(0, $line - $padding - 1);
        $end   = min($totalLines - 1, $line + $padding - 1);

        $snippet = [];

        for ($current = $start; $current <= $end; $current++) {
            $snippet[] = [
                'number'  => $current + 1,
                'content' => $lines[$current],
            ];
        }

        return $snippet;
    }

    private static function resolveBasePath(): ?string
    {
        if (!defined('\NAF_BASE_PATH')) {
            return null;
        }

        $path = realpath(\NAF_BASE_PATH);
        return $path ?: null;
    }

    private static function sendShutdownFallback(\Throwable $exception): void
    {
        // Whatever was buffered belongs to the request that just died; keep it
        // from being flushed in front of the fallback output.
        ResponseEmitter::clearOutputBuffers();

        $showDetails = self::shouldRenderDetailedView();

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'A fatal error occurred during shutdown.' . PHP_EOL);
            fwrite(STDERR, ($showDetails ? $exception->getMessage() : 'An unexpected internal error occurred.') . PHP_EOL);
            exit(1);
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('HTTP/1.1 500 Internal Server Error');
        }

        $localizedMessage = $showDetails
            ? '<p>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
            : '<p>An unexpected internal error occurred. Please try again later.</p>';

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body>';
        echo '<h1>Application Error</h1>';
        echo '<p>A fatal error occurred during shutdown.</p>';
        echo $localizedMessage;
        echo '</body></html>';

        exit(1);
    }

}
