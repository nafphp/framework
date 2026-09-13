<?php
declare(strict_types=1);

namespace Naf\Exceptions;

use Psr\Container\ContainerExceptionInterface;

class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
}
