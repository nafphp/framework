<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Core\ErrorHandler;
use Naf\Exceptions\HttpException;
use RuntimeException;
use Tests\NafTestCase;

final class ErrorHandlingTest extends NafTestCase
{
    public function testHttpErrorsKeepTheirStatusAndHideDetailsOutsideDev(): void
    {
        $previous = $_ENV['APP_ENV'] ?? null;
        $process = getenv('APP_ENV');
        $exception = new HttpException('sensitive-password', 403);
        try {
            putenv('APP_ENV');
            foreach (['prod', 'test', 'production', 'staging', '', null, ['dev']] as $environment) {
                if ($environment === null) {
                    unset($_ENV['APP_ENV']);
                } else {
                    $_ENV['APP_ENV'] = $environment;
                }
                $response = ErrorHandler::renderResponse($exception, ErrorHandler::resolveStatusCode($exception));
                self::assertSame(403, $response->getStatusCode());
                $body = (string) $response->getBody();
                self::assertStringNotContainsString('sensitive-password', $body);
                self::assertStringNotContainsString(__FILE__, $body);
                self::assertStringNotContainsString('testHttpErrorsKeepTheirStatus', $body);
            }
            $_ENV['APP_ENV'] = 'dev';
            self::assertStringContainsString('sensitive-password', (string) ErrorHandler::renderResponse($exception, 403)->getBody());
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previous;
            }
            $process === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $process);
        }
    }

    public function testInvalidExceptionStatusesFallBackToServerError(): void
    {
        self::assertSame(500, ErrorHandler::resolveStatusCode(new RuntimeException('secret')));
        self::assertSame(500, ErrorHandler::resolveStatusCode(new HttpException('secret', 999)));
        self::assertSame(404, ErrorHandler::resolveStatusCode(new HttpException('', 404)));
        $exception = new class extends RuntimeException {
            private function getStatusCode(): int
            {
                return 403;
            }
        };
        self::assertSame(500, ErrorHandler::resolveStatusCode($exception));
    }
}
