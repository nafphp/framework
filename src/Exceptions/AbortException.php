<?php
declare(strict_types=1);

namespace Naf\Exceptions;

class AbortException extends \Exception
{

    protected int $statusCode;

    public function __construct(string $message = '', int $statusCode = 500)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

}