<?php
declare(strict_types=1);

namespace Naf\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

class ServiceNotFoundException extends \LogicException implements NotFoundExceptionInterface
{
}
