<?php

declare(strict_types=1);

namespace Naf\Exceptions;

/** @api */
class MethodNotAllowedException extends HttpException
{
    /** @param list<string> $allowedMethods */
    public function __construct(private readonly array $allowedMethods)
    {
        parent::__construct('Method not allowed', 405);
    }

    /** @return list<string> */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
